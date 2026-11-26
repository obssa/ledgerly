#!/usr/bin/env bash
# Runs ON the cPanel server, called by .github/workflows/deploy.yml after it uploaded ledgerly-<sha>.tar.gz:
#
#   ssh target "bash ~/ledgerly/deploy/release.sh <sha>" <<< "<owner database password>"
#
# The owner password arrives on stdin and lives only in this process: it is never written to the server, so
# the running API (and anything that could break into it) never has the login that bypasses row-level
# security (SECURITY.md §4). The API's own .env holds only the restricted login.
#
# Layout (docs/runbook/cpanel.md):
#   ~/ledgerly/releases/<sha>/{api,web}   one folder per release
#   ~/ledgerly/current -> releases/<sha>  what is live: the API docroot is current/api/public,
#                                         the Node.js app (~/ledgerly-web/app.js) starts current/web/server.js
#   ~/ledgerly/shared/api.env             the API's settings (restricted login only)
#   ~/ledgerly/shared/web.env             the web app's settings (LEDGERLY_API_URL)
#   ~/ledgerly/shared/storage/            uploads, logs, framework cache: kept across releases
set -euo pipefail

SHA="${1:?usage: release.sh <sha>}"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"
WEB_APP_ROOT="${LEDGERLY_WEB_APP_ROOT:-$HOME/ledgerly-web}"
KEEP=5
DEMO_LOGINS="${DEMO_LOGINS:-}"   # true | false | empty (leave the server's setting as it is)
case "$DEMO_LOGINS" in true|false|'') ;; *) echo "DEMO_LOGINS must be true or false"; exit 1 ;; esac

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin."; exit 1; }
[ -f "$ROOT/shared/api.env" ] || { echo "Missing $ROOT/shared/api.env: run the one-time provisioning (docs/runbook/cpanel.md)."; exit 1; }
BUNDLE="$ROOT/incoming/ledgerly-$SHA.tar.gz"
[ -f "$BUNDLE" ] || { echo "No upload at $BUNDLE"; exit 1; }

# How much of the account's process limit is in use (shared with any other app on it): counts by program name only, never
# arguments, so nothing private reaches the deploy log. When this nears the limit, nothing new can start.
echo "== Processes on this account: $(ps -u "$(id -un)" --no-headers 2>/dev/null | wc -l) (limit $(ulimit -u))"
ps -u "$(id -un)" -o comm= 2>/dev/null | sort | uniq -c | sort -rn | head -8 || true

REL="$ROOT/releases/$SHA"
echo "== Unpack $SHA"
rm -rf "$REL" && mkdir -p "$REL"
tar -xzf "$BUNDLE" -C "$REL"
rm -f "$BUNDLE"

echo "== Shared settings and storage"
mkdir -p "$ROOT/shared/storage/framework/cache" "$ROOT/shared/storage/framework/views" "$ROOT/shared/storage/logs" "$ROOT/shared/storage/app"
ln -sfn "$ROOT/shared/api.env" "$REL/api/.env"
rm -rf "$REL/api/storage" && ln -sfn "$ROOT/shared/storage" "$REL/api/storage"
mkdir -p "$REL/api/bootstrap/cache"
# Server-specific lines for the API's .htaccess (on cPanel: which PHP serves the folder), kept out of the repo.
if [ -f "$ROOT/shared/api.htaccess" ]; then
  { cat "$ROOT/shared/api.htaccess"; echo; cat "$REL/api/public/.htaccess"; } > "$REL/api/public/.htaccess.new"
  mv "$REL/api/public/.htaccess.new" "$REL/api/public/.htaccess"
fi

cd "$REL/api"
OWNER_USERNAME="$(sed -n 's/^DB_OWNER_USERNAME=//p' .env)"
[ -n "$OWNER_USERNAME" ] || { echo "DB_OWNER_USERNAME is not set in shared/api.env"; exit 1; }
as_owner() { DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan "$@" --no-interaction; }

echo "== Migrate as the owner (before any config cache, so the password is never cached)"
as_owner ledgerly:create-schema
as_owner migrate --database=owner --force

# Demo sign-in (config/demo.php): set from the repository variable DEMO_LOGINS, then the demo company and its shared
# accounts are added if missing. A failed demo setup does not stop the release: the books matter, the demo does not.
if [ -n "$DEMO_LOGINS" ]; then
  sed -i '/^DEMO_LOGINS=/d' "$ROOT/shared/api.env"
  printf 'DEMO_LOGINS=%s\n' "$DEMO_LOGINS" >> "$ROOT/shared/api.env"
fi
if grep -q '^DEMO_LOGINS=true' .env; then
  echo "== Demo sign-in"
  as_owner ledgerly:demo-setup || echo "WARNING: the demo setup failed; this release goes live without it"
fi
unset OWNER_PASSWORD

echo "== The API's login must be restricted, or this release does not go live"
"$PHP" artisan ledgerly:check-database-roles --no-interaction

echo "== Cache configuration and routes"
"$PHP" artisan config:cache --no-interaction
"$PHP" artisan route:cache --no-interaction

echo "== Keep the script files of earlier releases"
# A browser tab opened before this release still runs the old app, and asks for the old build's script files
# when it opens a page it has not loaded yet. Without them it shows "This page couldn't load" until refreshed.
# The files are named by their content, so the old ones sit beside the new ones without clashing; they keep
# their own dates (-p) and are dropped 14 days after their build.
PREVIOUS="$(readlink "$ROOT/current" 2>/dev/null || true)"
if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS/web/.next/static" ] && [ -d "$REL/web/.next/static" ]; then
  cp -Rnp "$PREVIOUS/web/.next/static/." "$REL/web/.next/static/" || echo "WARNING: earlier script files were not copied"
  find "$REL/web/.next/static" -type f -mtime +14 -delete || true
fi

echo "== The API's address on this server, for the web app's calls to it (web-app.js, pinApiAddress)"
# Read from cPanel's own record of the API's domain on every release, so a move to another server or address can't
# leave an old one behind. If cPanel can't tell, the setting is removed and the web app uses public DNS (slower
# behind Cloudflare, never wrong).
API_HOST="$(sed -n 's|^LEDGERLY_API_URL=https\{0,1\}://\([^/:]*\).*|\1|p' "$ROOT/shared/web.env" | head -1)"
API_ADDRESS=""
if [ -n "$API_HOST" ] && command -v uapi >/dev/null 2>&1; then
  API_ADDRESS="$(uapi --output=json DomainInfo single_domain_data domain="$API_HOST" 2>/dev/null | grep -oE '"ip":"[0-9.]+"' | head -1 | cut -d'"' -f4 || true)"
fi
sed -i '/^LEDGERLY_API_ADDRESS=/d' "$ROOT/shared/web.env"
# Only an address where the API answers over HTTPS, with a valid certificate for its name.
if [[ $API_ADDRESS =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] \
  && [ "$(curl -s -o /dev/null -m 15 -w '%{http_code}' --resolve "$API_HOST:443:$API_ADDRESS" "https://$API_HOST/up" || true)" = 200 ]; then
  printf 'LEDGERLY_API_ADDRESS=%s\n' "$API_ADDRESS" >> "$ROOT/shared/web.env"
  echo "$API_HOST is reached at $API_ADDRESS from here"
else
  echo "WARNING: cPanel did not give an address for '$API_HOST'; the web app looks it up in public DNS"
fi

echo "== The Node.js app's startup file"
# Installed with the release it belongs to, never before: a deploy that fails earlier leaves the running app alone.
# The one it replaces is kept for rollback.sh, which puts it back with the previous release.
if [ -f "$ROOT/deploy/web-app.js" ]; then
  [ -f "$WEB_APP_ROOT/app.js" ] && cp -p "$WEB_APP_ROOT/app.js" "$ROOT/shared/app.js.previous"
  cp "$ROOT/deploy/web-app.js" "$WEB_APP_ROOT/app.js"
fi

echo "== Switch"
ln -sfn "$REL" "$ROOT/current.next" && mv -Tf "$ROOT/current.next" "$ROOT/current"
[ -n "$PREVIOUS" ] && [ "$PREVIOUS" != "$REL" ] && printf '%s\n' "$PREVIOUS" > "$ROOT/shared/previous-release"
mkdir -p "$WEB_APP_ROOT/tmp" && touch "$WEB_APP_ROOT/tmp/restart.txt"   # the Node.js app restarts on its next request
"$PHP" artisan queue:restart --no-interaction || true                    # cron workers pick up the new code

echo "== Cron jobs"
LEDGERLY_ROOT="$ROOT" PHP_BIN="$PHP" bash "$ROOT/deploy/cron.sh" || echo "WARNING: the cron jobs were not checked"

echo "== Keep the newest $KEEP releases"
find "$ROOT/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP + 1)) | cut -d' ' -f2- |
  while read -r old; do
    [ "$old" = "$REL" ] || [ "$old" = "$PREVIOUS" ] || rm -rf "$old"
  done

echo "Live: $SHA"

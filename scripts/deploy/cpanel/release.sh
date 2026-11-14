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

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin."; exit 1; }
[ -f "$ROOT/shared/api.env" ] || { echo "Missing $ROOT/shared/api.env: run the one-time provisioning (docs/runbook/cpanel.md)."; exit 1; }
BUNDLE="$ROOT/incoming/ledgerly-$SHA.tar.gz"
[ -f "$BUNDLE" ] || { echo "No upload at $BUNDLE"; exit 1; }

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

cd "$REL/api"
OWNER_USERNAME="$(sed -n 's/^DB_OWNER_USERNAME=//p' .env)"
[ -n "$OWNER_USERNAME" ] || { echo "DB_OWNER_USERNAME is not set in shared/api.env"; exit 1; }
as_owner() { DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan "$@" --no-interaction; }

echo "== Migrate as the owner (before any config cache, so the password is never cached)"
as_owner ledgerly:create-schema
as_owner migrate --database=owner --force
unset OWNER_PASSWORD

echo "== The API's login must be restricted, or this release does not go live"
"$PHP" artisan ledgerly:check-database-roles --no-interaction

echo "== Cache configuration and routes"
"$PHP" artisan config:cache --no-interaction
"$PHP" artisan route:cache --no-interaction

echo "== Switch"
PREVIOUS="$(readlink "$ROOT/current" 2>/dev/null || true)"
ln -sfn "$REL" "$ROOT/current.next" && mv -Tf "$ROOT/current.next" "$ROOT/current"
[ -n "$PREVIOUS" ] && [ "$PREVIOUS" != "$REL" ] && printf '%s\n' "$PREVIOUS" > "$ROOT/shared/previous-release"
mkdir -p "$WEB_APP_ROOT/tmp" && touch "$WEB_APP_ROOT/tmp/restart.txt"   # the Node.js app restarts on its next request
"$PHP" artisan queue:restart --no-interaction || true                    # cron workers pick up the new code

echo "== Keep the newest $KEEP releases"
find "$ROOT/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP + 1)) | cut -d' ' -f2- |
  while read -r old; do
    [ "$old" = "$REL" ] || [ "$old" = "$PREVIOUS" ] || rm -rf "$old"
  done

echo "Live: $SHA"

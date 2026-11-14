#!/usr/bin/env bash
# One-time setup of the cPanel account for Ledgerly (docs/runbook/cpanel.md). Safe to run again: it creates
# what is missing and never overwrites existing settings or data.
#
#   bash ~/ledgerly/deploy/provision.sh
#
# Expects ~/.ledgerly-db-secrets (written when the database and its two logins were created in cPanel) to hold
# DB_PASSWORD, the restricted login's password. The owner password in that file is NOT copied anywhere on the
# server: it belongs in the GitHub secret DEPLOY_DB_OWNER_PASSWORD, after which the file is deleted.
set -euo pipefail

U="$(whoami)"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
WEB_APP_ROOT="${LEDGERLY_WEB_APP_ROOT:-$HOME/ledgerly-web}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"
API_URL="${API_URL:-https://ledgerly-api.orcamind.dev}"
WEB_URL="${WEB_URL:-https://ledgerly.orcamind.dev}"
DB_NAME="${DB_NAME:-${U}_ledgerly}"
DB_OWNER="${DB_OWNER:-${U}_lowner}"
DB_WEB="${DB_WEB:-${U}_lweb}"

mkdir -p "$ROOT/releases" "$ROOT/incoming" "$ROOT/deploy" "$WEB_APP_ROOT/tmp" \
         "$ROOT/shared/storage/app" "$ROOT/shared/storage/logs" "$ROOT/shared/storage/framework/cache" "$ROOT/shared/storage/framework/views"
chmod 700 "$ROOT/shared"

# Something answers on both addresses before the first release lands, so the domains can be attached.
if [ ! -e "$ROOT/current" ]; then
  P="$ROOT/releases/000000-placeholder"
  mkdir -p "$P/api/public" "$P/web"
  printf '<!doctype html><title>Ledgerly</title><p>Ledgerly is being deployed.</p>\n' > "$P/api/public/index.html"
  cat > "$P/web/server.js" <<'JS'
require("http").createServer((req, res) => {
  res.writeHead(503, { "content-type": "text/html", "retry-after": "60" });
  res.end("<!doctype html><title>Ledgerly</title><p>Ledgerly is being deployed.</p>");
}).listen(process.env.PORT || 3000);
JS
  ln -sfn "$P" "$ROOT/current"
  echo "created placeholder release"
fi

if [ ! -f "$ROOT/shared/api.env" ]; then
  WEB_PASSWORD="$(sed -n 's/^DB_PASSWORD=//p' "$HOME/.ledgerly-db-secrets" 2>/dev/null || true)"
  [ -n "$WEB_PASSWORD" ] || { echo "No DB_PASSWORD in ~/.ledgerly-db-secrets: create the database logins first (runbook step 1)."; exit 1; }
  APP_KEY="base64:$(openssl rand -base64 32)"
  ( umask 077; cat > "$ROOT/shared/api.env" <<EOF
APP_NAME=Ledgerly
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$API_URL
APP_LOCALE=en
FRONTEND_URL=$WEB_URL
LOG_CHANNEL=daily
LOG_LEVEL=info

# PostgreSQL 13 on this server. The API runs as the restricted login; the owner login is used only by the
# deploy, with a password that never touches this server (SECURITY.md §4).
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_WEB
DB_PASSWORD=$WEB_PASSWORD
DB_OWNER_USERNAME=$DB_OWNER
DB_APP_ROLE=$DB_WEB
DB_SCHEMA=ledgerly
# cPanel's PostgreSQL lets a login connect only as a member of the database-owner role (pg_hba samerole).
# The role check accepts that and still refuses table ownership or RLS bypass (SECURITY.md finding 23).
DB_ALLOW_OWNER_ROLE_MEMBERSHIP=true

# No Redis on shared hosting: cache and queue in the database, queue worked by cron.
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=array
FILESYSTEM_DISK=local

SANCTUM_STATEFUL_DOMAINS=${WEB_URL#https://}
SANCTUM_TOKEN_MINUTES=720
# Cloudflare sits in front (https://www.cloudflare.com/ips/): trusting its ranges gives each client its own
# sign-in lockout and lets the API see HTTPS.
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32

EINVOICE_FORCE_SANDBOX=true
EOF
  )
  echo "created shared/api.env (restricted login only)"
fi

# The account's default PHP is older than Ledgerly needs. cPanel picks PHP per folder through an .htaccess
# handler (bakery-ops does the same), so the API's folder gets its own; release.sh puts it into every release.
if [ ! -f "$ROOT/shared/api.htaccess" ]; then
  printf '# PHP 8.3 for the API folder (cPanel per-directory handler)\nAddHandler %s .php .php8 .phtml\n' \
    "${PHP_HANDLER:-application/x-httpd-alt-php83___lsphp}" > "$ROOT/shared/api.htaccess"
  echo "created shared/api.htaccess"
fi

if [ ! -f "$ROOT/shared/web.env" ]; then
  ( umask 077; printf 'LEDGERLY_API_URL=%s\n' "$API_URL" > "$ROOT/shared/web.env" )
  echo "created shared/web.env"
fi

[ -f "$ROOT/deploy/web-app.js" ] && cp "$ROOT/deploy/web-app.js" "$WEB_APP_ROOT/app.js"

# The crontab is shared with every other app on this cPanel account (bakery-ops). Lines are only ever appended;
# nothing else in it is changed, and if it cannot be read for any reason other than "there is none yet", it is
# left alone, so a read failure can never wipe another app's jobs. A copy is kept before the first change.
add_cron() {
  local current
  if ! current="$(crontab -l 2>&1)"; then
    case "$current" in
      *"no crontab"*) current="" ;;
      *) echo "Could not read the crontab ($current): not touching it. Add this line by hand: $1"; return 1 ;;
    esac
  fi
  grep -qxF -- "$1" <<<"$current" && return 0
  [ -f "$ROOT/shared/crontab.before-ledgerly" ] || printf '%s\n' "$current" > "$ROOT/shared/crontab.before-ledgerly"
  if [ -n "$current" ]; then printf '%s\n%s\n' "$current" "$1"; else printf '%s\n' "$1"; fi | crontab -
  echo "cron: $1"
}
add_cron "* * * * * cd $ROOT/current/api && $PHP artisan schedule:run >/dev/null 2>&1"
add_cron "* * * * * cd $ROOT/current/api && $PHP artisan queue:work --stop-when-empty --max-time=50 --tries=3 >/dev/null 2>&1"

echo "Provisioned $ROOT"

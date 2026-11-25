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
API_URL="${API_URL:-https://api.evenbooks.net}"
WEB_URL="${WEB_URL:-https://app.evenbooks.net}"
DB_NAME="${DB_NAME:-${U}_ledgerly}"
DB_OWNER="${DB_OWNER:-${U}_lowner}"
DB_WEB="${DB_WEB:-${U}_lweb}"
# true only when Cloudflare's proxy sits in front of both addresses. Otherwise no proxy is trusted: a request
# could claim any visitor's address in X-Forwarded-For and dodge the sign-in lockout.
BEHIND_CLOUDFLARE="${BEHIND_CLOUDFLARE:-false}"

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
# Its own database session: a failed, rolled-back request must not erase its own rate-limit count.
DB_CACHE_CONNECTION=cache
QUEUE_CONNECTION=database
SESSION_DRIVER=array
FILESYSTEM_DISK=local

SANCTUM_STATEFUL_DOMAINS=${WEB_URL#https://}
SANCTUM_TOKEN_MINUTES=720

EINVOICE_FORCE_SANDBOX=true
EOF
  )
  if [ "$BEHIND_CLOUDFLARE" = true ]; then
    cat >> "$ROOT/shared/api.env" <<'EOF2'

# Cloudflare sits in front (https://www.cloudflare.com/ips/): trusting its ranges gives each client its own
# sign-in lockout and lets the API see HTTPS.
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32
EOF2
  fi
  echo "created shared/api.env (restricted login only)"
fi

# The account's default PHP is older than Ledgerly needs. cPanel picks PHP per folder through an .htaccess
# handler, so the API's folder gets its own; release.sh puts it into every release.
if [ ! -f "$ROOT/shared/api.htaccess" ]; then
  printf '# PHP 8.3 for the API folder (cPanel per-directory handler)\nAddHandler %s .php .php8 .phtml\n' \
    "${PHP_HANDLER:-application/x-httpd-alt-php83___lsphp}" > "$ROOT/shared/api.htaccess"
  echo "created shared/api.htaccess"
fi

if [ ! -f "$ROOT/shared/web.env" ]; then
  ( umask 077; printf 'LEDGERLY_API_URL=%s\nLEDGERLY_WEB_URL=%s\n' "$API_URL" "$WEB_URL" > "$ROOT/shared/web.env" )
  echo "created shared/web.env"
fi
# Settings added after the first install: appended once, never changed.
grep -q '^DB_CACHE_CONNECTION=' "$ROOT/shared/api.env" || printf '\n# The cache on its own database session (rate limits survive a rolled-back request)\nDB_CACHE_CONNECTION=cache\n' >> "$ROOT/shared/api.env"
# Email (confirmation and reset links, SECURITY.md S3) goes out through the server's own mail system. The
# domain already publishes SPF and DKIM for this server. No mailbox or password is needed to send.
grep -q '^MAIL_MAILER=' "$ROOT/shared/api.env" || printf '\n# Email through the server (sendmail); SPF and DKIM are published for the domain\nMAIL_MAILER=sendmail\nMAIL_FROM_ADDRESS=%s\nMAIL_FROM_NAME=Ledgerly\n' "${MAIL_FROM:-no-reply@evenbooks.net}" >> "$ROOT/shared/api.env"
# One secret on both sides: with it the web server may tell the API which visitor it acts for, so sign-in
# limits count people, not the web server (SECURITY.md S16).
if ! grep -q '^WEB_SERVER_KEY=.' "$ROOT/shared/api.env" || ! grep -q '^LEDGERLY_WEB_KEY=.' "$ROOT/shared/web.env"; then
  WEB_KEY="$(openssl rand -hex 32)"
  sed -i '/^WEB_SERVER_KEY=/d' "$ROOT/shared/api.env"; sed -i '/^LEDGERLY_WEB_KEY=/d' "$ROOT/shared/web.env"
  printf '\n# Shared with the web app (LEDGERLY_WEB_KEY in web.env)\nWEB_SERVER_KEY=%s\n' "$WEB_KEY" >> "$ROOT/shared/api.env"
  printf 'LEDGERLY_WEB_KEY=%s\n' "$WEB_KEY" >> "$ROOT/shared/web.env"
  unset WEB_KEY
  echo "created the web-server key (api.env and web.env)"
fi
# Behind Cloudflare the web app reads the visitor's address from Cloudflare's header; otherwise from the address
# the web server added last to X-Forwarded-For (apps/web/src/lib/clientAddress.ts). Appended once, never changed.
grep -q '^LEDGERLY_BEHIND_CLOUDFLARE=' "$ROOT/shared/web.env" || printf 'LEDGERLY_BEHIND_CLOUDFLARE=%s\n' "$BEHIND_CLOUDFLARE" >> "$ROOT/shared/web.env"
# The web app's public address, for redirects (apps/web/src/proxy.ts).
grep -q '^LEDGERLY_WEB_URL=' "$ROOT/shared/web.env" || printf 'LEDGERLY_WEB_URL=%s\n' "$WEB_URL" >> "$ROOT/shared/web.env"

[ -f "$ROOT/deploy/web-app.js" ] && cp "$ROOT/deploy/web-app.js" "$WEB_APP_ROOT/app.js"

# Ledgerly's two cron jobs (scheduler, queue). The crontab is shared with any other app on this cPanel account:
# cron.sh adds Ledgerly's lines or brings older ones up to date and changes no other line, and leaves
# the crontab alone if it cannot be read. Each job runs at most once at a time and for a bounded time.
LEDGERLY_ROOT="$ROOT" PHP_BIN="$PHP" bash "$ROOT/deploy/cron.sh"

echo "Provisioned $ROOT"

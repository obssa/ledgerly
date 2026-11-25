#!/usr/bin/env bash
# Runs ON the cPanel server, called by .github/workflows/billing.yml: the operator's hand on an Evenbooks subscription
# (053), as the owner role, since the application cannot change it.
#
#   ssh target "bash ~/ledgerly/deploy/billing.sh <organisation-or-company-id> <action> [plan] [paid-until] [days] [reference]" <<< "<owner password>"
#
# The owner password arrives on stdin and lives only in this process (release.sh does the same). Every argument is
# checked against a fixed pattern here before it reaches artisan.
set -euo pipefail

ID="${1:?organisation or company id}"; ACTION="${2:?action}"; PLAN="${3:-}"; UNTIL="${4:-}"; DAYS="${5:-}"; REF="${6:-}"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

[[ "$ID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || { echo "The id is not a UUID."; exit 1; }
case "$ACTION" in show|activate|plan|extend-trial|suspend|cancel) ;; *) echo "Unknown action."; exit 1 ;; esac
[ -z "$PLAN" ] || [[ "$PLAN" =~ ^(starter|essentials|plus|advanced|accountant)$ ]] || { echo "Unknown plan."; exit 1; }
[ -z "$UNTIL" ] || [[ "$UNTIL" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "paid-until is a date (Y-m-d)."; exit 1; }
[ -z "$DAYS" ] || [[ "$DAYS" =~ ^[0-9]{1,2}$ ]] || { echo "days is a number."; exit 1; }
[ -z "$REF" ] || [[ "$REF" =~ ^[A-Za-z0-9\ ./_-]{1,60}$ ]] || { echo "The reference has letters, digits, spaces and . / _ - only."; exit 1; }

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin."; exit 1; }
cd "$ROOT/current/api"
OWNER_USERNAME="$(sed -n 's/^DB_OWNER_USERNAME=//p' "$ROOT/shared/api.env")"

args=("$ID" "--by=GitHub billing workflow")
[ -n "$PLAN" ] && args+=("--plan=$PLAN")
[ -n "$REF" ] && args+=("--reference=$REF")
case "$ACTION" in
  activate) [ -n "$UNTIL" ] || { echo "activate needs paid-until."; exit 1; }; args+=("--activate" "--paid-until=$UNTIL") ;;
  extend-trial) [ -n "$DAYS" ] || { echo "extend-trial needs days."; exit 1; }; args+=("--extend-trial=$DAYS") ;;
  suspend) args+=("--suspend") ;;
  cancel) args+=("--cancel") ;;
esac

# The cached configuration has no owner password (release.sh); this one command reads it from its own environment.
DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan config:clear --no-interaction >/dev/null
trap '"$PHP" artisan config:cache --no-interaction >/dev/null' EXIT
DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan ledgerly:subscription "${args[@]}" --no-interaction

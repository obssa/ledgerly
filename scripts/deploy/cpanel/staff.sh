#!/usr/bin/env bash
# Makes, re-invites or switches off an Evenbooks staff account (SECURITY.md S8), run by .github/workflows/staff.yml on
# the server. The owner database password comes on stdin and is used for this one artisan run only; it is never
# written anywhere. Arguments: email, action (add, resend, deactivate), name, who asked.
set -euo pipefail

EMAIL="${1:?email}"; ACTION="${2:?action}"; NAME="${3:-}"; BY="${4:-operator}"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

[[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,190}\.[A-Za-z]{2,}$ ]] || { echo "The email is not an email address."; exit 1; }
case "$ACTION" in add|resend|deactivate) ;; *) echo "Unknown action."; exit 1 ;; esac
name_re="^[A-Za-z .'-]{2,120}$"
[ -z "$NAME" ] || [[ "$NAME" =~ $name_re ]] || { echo "The name has letters, spaces and . ' - only."; exit 1; }
[[ "$BY" =~ ^[A-Za-z0-9-]{1,40}$ ]] || BY="operator"

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin."; exit 1; }
cd "$ROOT/current/api"
OWNER_USERNAME="$(sed -n 's/^DB_OWNER_USERNAME=//p' "$ROOT/shared/api.env")"

args=("$EMAIL" "--by=$BY")
case "$ACTION" in
  add) [ -n "$NAME" ] || { echo "add needs a name."; exit 1; }; args+=("--name=$NAME") ;;
  resend) args+=("--resend") ;;
  deactivate) args+=("--deactivate") ;;
esac

DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan config:clear --no-interaction >/dev/null
trap '"$PHP" artisan config:cache --no-interaction >/dev/null' EXIT
DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan ledgerly:staff "${args[@]}" --no-interaction

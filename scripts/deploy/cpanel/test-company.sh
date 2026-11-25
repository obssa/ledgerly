#!/usr/bin/env bash
# Makes a company a test company with the given owners (schema 104, `ledgerly:test-company`), run by
# .github/workflows/test-company.yml on the server. The owner database password comes on stdin and is used for this one
# artisan run only; it is never written anywhere. Passwords of accounts made now go to shared/test-accounts.txt
# (mode 600) and nowhere else: not the run's output, not email. Read that file once and delete it.
# Arguments: company id, owners as "email|Name;email|Name", who asked.
set -euo pipefail

COMPANY="${1:?company id}"; OWNERS="${2:-}"; BY="${3:-operator}"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
PHP="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

[[ "$COMPANY" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || { echo "The company is not a company id."; exit 1; }
[[ "$BY" =~ ^[A-Za-z0-9-]{1,40}$ ]] || BY="operator"
args=("$COMPANY" "--by=$BY" "--accounts-file=$ROOT/shared/test-accounts.txt")
owner_re="^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,190}\.[A-Za-z]{2,}\|[A-Za-z0-9 .'-]{2,120}$"
IFS=';' read -r -a list <<< "$OWNERS"
for o in "${list[@]}"; do
  o="$(printf '%s' "$o" | sed 's/^ *//; s/ *$//')"
  [ -z "$o" ] && continue
  [[ "$o" =~ $owner_re ]] || { echo "Each owner is email|Name (letters, digits, spaces and . ' - in the name)."; exit 1; }
  args+=("--owner=$o")
done

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin."; exit 1; }
cd "$ROOT/current/api"
OWNER_USERNAME="$(sed -n 's/^DB_OWNER_USERNAME=//p' "$ROOT/shared/api.env")"

DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan config:clear --no-interaction >/dev/null
trap '"$PHP" artisan config:cache --no-interaction >/dev/null' EXIT
DB_OWNER_USERNAME="$OWNER_USERNAME" DB_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan ledgerly:test-company "${args[@]}" --no-interaction

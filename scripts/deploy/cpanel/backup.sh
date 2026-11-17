#!/usr/bin/env bash
# Runs ON the cPanel server, called by .github/workflows/backup.yml (SECURITY.md S11):
#
#   ssh target "bash ~/ledgerly/deploy/backup.sh dump"  <<< "<owner database password>"   # the database, to stdout
#   ssh target "bash ~/ledgerly/deploy/backup.sh heads" <<< "<owner database password>"   # audit chain heads, JSON
#
# The owner password arrives on stdin and lives only in this process, as for a release (release.sh): it is never
# written to the server. The dump goes to stdout in PostgreSQL's custom format and is encrypted by the workflow
# before it touches a disk; nothing is kept here.
set -euo pipefail

MODE="${1:?usage: backup.sh dump|heads}"
ROOT="${LEDGERLY_ROOT:-$HOME/ledgerly}"
ENV_FILE="$ROOT/shared/api.env"
[ -f "$ENV_FILE" ] || { echo "Missing $ENV_FILE" >&2; exit 1; }

IFS= read -r OWNER_PASSWORD || true
[ -n "${OWNER_PASSWORD:-}" ] || { echo "The owner database password was not given on stdin." >&2; exit 1; }

setting() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed 's/^"\(.*\)"$/\1/'; }
HOST="$(setting DB_HOST)"; PORT="$(setting DB_PORT)"; DB="$(setting DB_DATABASE)"; OWNER="$(setting DB_OWNER_USERNAME)"; SCHEMA="$(setting DB_SCHEMA)"
SCHEMA="${SCHEMA:-public}"
for v in HOST DB OWNER; do [ -n "${!v}" ] || { echo "DB_${v} is not set in $ENV_FILE" >&2; exit 1; }; done
case "$SCHEMA" in *[!a-z0-9_]*) echo "DB_SCHEMA must be a plain name" >&2; exit 1 ;; esac

export PGPASSWORD="$OWNER_PASSWORD" PGHOST="$HOST" PGPORT="${PORT:-5432}" PGUSER="$OWNER" PGDATABASE="$DB" PGCONNECT_TIMEOUT=20
unset OWNER_PASSWORD

case "$MODE" in
  dump)
    command -v pg_dump >/dev/null || { echo "pg_dump is not installed on this server" >&2; exit 1; }
    # Only Ledgerly's schema; owners and grants are left out (a restore recreates them for its own roles).
    exec pg_dump --format=custom --compress=6 --no-owner --no-privileges --schema="$SCHEMA"
    ;;
  heads)
    # The last hash of each company's audit chain: kept off this server, so a database administrator who rewrote
    # history here could not also rewrite the record of where each chain stood (SECURITY.md S11).
    exec psql -X -At -v ON_ERROR_STOP=1 -c "SELECT coalesce(json_agg(h ORDER BY h.company_id), '[]') FROM (
        SELECT DISTINCT ON (company_id) company_id, id AS last_id, row_hash, created_at
          FROM \"$SCHEMA\".audit_log ORDER BY company_id, id DESC) h"
    ;;
  *) echo "usage: backup.sh dump|heads" >&2; exit 1 ;;
esac

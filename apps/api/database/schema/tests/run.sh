#!/usr/bin/env bash
# Applies the schema to a fresh database and runs every database test.
#   PGHOST=... PGPORT=... PGUSER=<owner role> ./run.sh [database-name]
set -euo pipefail
cd "$(dirname "$0")/.."
DB="${1:-ledgerly_schema_test}"
psql -d postgres -qc "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in 00*.sql; do psql -d "$DB" -q -v ON_ERROR_STOP=1 -f "$f" >/dev/null; done
psql -d "$DB" -v ON_ERROR_STOP=1 -f tests/ledger_guards_test.sql 2>&1 | grep -E "PASS|FAIL|ERROR|passed" 
bash tests/concurrency_test.sh "$DB"

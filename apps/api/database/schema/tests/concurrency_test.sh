#!/usr/bin/env bash
# Two real sessions: posting and period closing cannot slip past each other, and two companies never block each other.
set -euo pipefail
DB="$1"
A=00000000-0000-0000-0000-00000000000a
B=00000000-0000-0000-0000-00000000000b
post() { # company, entry number, account, hold seconds
cat <<SQL
SET ROLE ledgerly_web;
BEGIN;
SELECT set_config('app.company_id', '$1', true);
INSERT INTO journal_entries (id, company_id, number, entry_date, currency) VALUES ('00000000-0000-0000-0000-0000000c$2', '$1', $2, '2026-11-20', 'ETB');
INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
  ('$1', '00000000-0000-0000-0000-0000000c$2', 1, '$3', 10, 0), ('$1', '00000000-0000-0000-0000-0000000c$2', 2, '$3', 0, 10);
SELECT pg_sleep($4);
COMMIT;
SQL
}

# C1: while a posting is in flight, closing the period waits (here: times out); it does not slip past.
post $A 9001 00000000-0000-0000-0000-000000000a11 3 | psql -d "$DB" -q -v ON_ERROR_STOP=1 >/dev/null &
sleep 1
if psql -d "$DB" -q -v ON_ERROR_STOP=1 -c "SET lock_timeout = '500ms'" \
     -c "UPDATE companies SET books_closed_until = '2026-11-30' WHERE id = '$A'" >/dev/null 2>&1; then
  echo "FAIL C1: period closed while a posting was in flight"; exit 1
fi
# C2: a posting in another company is not blocked by company A's open transaction.
START=$(date +%s%N)
post $B 9002 00000000-0000-0000-0000-000000000b11 0 | psql -d "$DB" -q -v ON_ERROR_STOP=1 >/dev/null
MS=$(( ($(date +%s%N) - START) / 1000000 ))
[ "$MS" -lt 1500 ] || { echo "FAIL C2: other company waited ${MS} ms"; exit 1; }
wait
echo "PASS C1 period close waits for a posting in flight"
echo "PASS C2 another company posts without waiting (${MS} ms)"

# C3: a posting that arrives during a close waits, then is refused by the new lock date.
( psql -d "$DB" -q -v ON_ERROR_STOP=1 >/dev/null <<SQL
BEGIN;
UPDATE companies SET books_closed_until = '2026-11-30' WHERE id = '$A';
SELECT pg_sleep(2);
COMMIT;
SQL
) &
sleep 1
if post $A 9003 00000000-0000-0000-0000-000000000a11 0 | psql -d "$DB" -q -v ON_ERROR_STOP=1 >/dev/null 2>&1; then
  echo "FAIL C3: posting slipped into a period being closed"; exit 1
fi
wait
echo "PASS C3 posting during a close waits, then is refused"
psql -d "$DB" -q -c "UPDATE companies SET books_closed_until = NULL WHERE id = '$A'"
N=$(psql -d "$DB" -Atc "SELECT count(*) FROM ledger_verify('$A')")
[ "$N" = "1" ] || { echo "FAIL C4: expected only the T25 drift, got $N problems"; exit 1; }
echo "PASS C4 ledger still sound after concurrent postings"

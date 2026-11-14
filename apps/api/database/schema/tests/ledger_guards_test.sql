-- Self-checking tests for the database-level ledger guards and tenant isolation.
-- Run against an EMPTY database that has 001..007 applied, as the owner/superuser:
--   psql -v ON_ERROR_STOP=1 -f tests/ledger_guards_test.sql
-- Every block raises (and psql stops) if a guard does not behave as expected. Prints PASS lines.

\set QUIET on
SET client_min_messages = notice;

-- ---------- fixtures (as owner, bypassing RLS) ----------
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ledgerly_web') THEN
    CREATE ROLE ledgerly_web LOGIN IN ROLE ledgerly_app;
  END IF;
END $$;

INSERT INTO organisations (id, name) VALUES ('00000000-0000-0000-0000-0000000000a0', 'Test Org'), ('00000000-0000-0000-0000-0000000000a9', 'Unrelated Org');
INSERT INTO users (id, name, email, password) VALUES
  ('00000000-0000-0000-0000-0000000000f1', 'Hana', 'hana@example.test', 'x'),
  ('00000000-0000-0000-0000-0000000000f2', 'Other', 'other@example.test', 'x');
INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency) VALUES
  ('00000000-0000-0000-0000-00000000000a', '00000000-0000-0000-0000-0000000000a0', 'Company A', 'ET', 'ETB'),
  ('00000000-0000-0000-0000-00000000000b', '00000000-0000-0000-0000-0000000000a0', 'Company B', 'KE', 'KES');
INSERT INTO memberships (company_id, user_id, role) VALUES
  ('00000000-0000-0000-0000-00000000000a', '00000000-0000-0000-0000-0000000000f1', 'owner'),
  ('00000000-0000-0000-0000-00000000000b', '00000000-0000-0000-0000-0000000000f2', 'owner');
INSERT INTO accounts (id, company_id, code, name, type, system_key) VALUES
  ('00000000-0000-0000-0000-000000000a11', '00000000-0000-0000-0000-00000000000a', '1200', 'Accounts receivable', 'asset', 'accounts_receivable'),
  ('00000000-0000-0000-0000-000000000a40', '00000000-0000-0000-0000-00000000000a', '4000', 'Sales', 'income', 'sales'),
  ('00000000-0000-0000-0000-000000000a22', '00000000-0000-0000-0000-00000000000a', '2300', 'VAT payable', 'liability', 'vat_payable'),
  ('00000000-0000-0000-0000-000000000b11', '00000000-0000-0000-0000-00000000000b', '1200', 'Accounts receivable', 'asset', 'accounts_receivable');

-- ---------- act as the application ----------
SET ROLE ledgerly_web;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false);
SELECT set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false);

-- T1: a balanced entry posts
DO $$ BEGIN
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
    VALUES ('00000000-0000-0000-0000-00000000e001', app_company_id(), 1, '2026-11-02', 'ETB', 'invoice');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e001', 1, '00000000-0000-0000-0000-000000000a11', 501745, 0, 501745),
    (app_company_id(), '00000000-0000-0000-0000-00000000e001', 2, '00000000-0000-0000-0000-000000000a40', 0, 436300, -436300),
    (app_company_id(), '00000000-0000-0000-0000-00000000e001', 3, '00000000-0000-0000-0000-000000000a22', 0, 65445, -65445);
  SET CONSTRAINTS ALL IMMEDIATE;
  RAISE NOTICE 'PASS T1 balanced entry posts';
END $$;

-- T2: an unbalanced entry is refused
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency)
      VALUES ('00000000-0000-0000-0000-00000000e002', app_company_id(), 2, '2026-11-02', 'ETB');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e002', 1, '00000000-0000-0000-0000-000000000a11', 100, 0),
      (app_company_id(), '00000000-0000-0000-0000-00000000e002', 2, '00000000-0000-0000-0000-000000000a40', 0, 99.99);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'FAIL T2: unbalanced entry was accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'PASS T2 unbalanced entry refused (%)', SQLERRM;
  END;
END $$;

-- T3: a single-line entry is refused
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency)
      VALUES ('00000000-0000-0000-0000-00000000e003', app_company_id(), 3, '2026-11-02', 'ETB');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e003', 1, '00000000-0000-0000-0000-000000000a11', 100, 0);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'FAIL T3: single-line entry was accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'PASS T3 single-line entry refused';
  END;
END $$;

-- T4: an entry with no lines is refused
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency)
      VALUES ('00000000-0000-0000-0000-00000000e004', app_company_id(), 4, '2026-11-02', 'ETB');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'FAIL T4: entry without lines was accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'PASS T4 entry without lines refused';
  END;
END $$;

-- T5: posted lines and entries are immutable; only posted -> reversed is allowed
DO $$ BEGIN
  BEGIN
    UPDATE journal_lines SET debit = 1 WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e001' AND line_no = 1;
    RAISE EXCEPTION 'FAIL T5a: line update accepted';
  EXCEPTION WHEN insufficient_privilege THEN RAISE NOTICE 'PASS T5a line update refused'; END;
  BEGIN
    DELETE FROM journal_entries WHERE id = '00000000-0000-0000-0000-00000000e001';
    RAISE EXCEPTION 'FAIL T5b: entry delete accepted';
  EXCEPTION WHEN insufficient_privilege THEN RAISE NOTICE 'PASS T5b entry delete refused'; END;
  BEGIN
    UPDATE journal_entries SET memo = 'edited' WHERE id = '00000000-0000-0000-0000-00000000e001';
    RAISE EXCEPTION 'FAIL T5c: entry memo edit accepted';
  EXCEPTION WHEN insufficient_privilege THEN RAISE NOTICE 'PASS T5c entry edit refused'; END;
  UPDATE journal_entries SET status = 'reversed' WHERE id = '00000000-0000-0000-0000-00000000e001';
  RAISE NOTICE 'PASS T5d marking an entry reversed is allowed';
END $$;

-- T6: nothing posts into a closed period
RESET ROLE;
UPDATE companies SET books_closed_until = '2026-09-30' WHERE id = '00000000-0000-0000-0000-00000000000a';
SET ROLE ledgerly_web;
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (company_id, number, entry_date, currency)
      VALUES (app_company_id(), 6, '2026-09-15', 'ETB');
    RAISE EXCEPTION 'FAIL T6: entry in closed period accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'PASS T6 closed period refused';
  END;
END $$;

-- T7: a line cannot use another company's account
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency)
      VALUES ('00000000-0000-0000-0000-00000000e007', app_company_id(), 7, '2026-11-02', 'ETB');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e007', 1, '00000000-0000-0000-0000-000000000b11', 10, 0);
    RAISE EXCEPTION 'FAIL T7: cross-company account accepted';
  EXCEPTION WHEN foreign_key_violation THEN
    RAISE NOTICE 'PASS T7 cross-company account refused';
  END;
END $$;

-- T8: row-level security isolates tenants
DO $$
DECLARE n integer;
BEGIN
  SELECT count(*) INTO n FROM accounts;
  IF n <> 3 THEN RAISE EXCEPTION 'FAIL T8a: expected 3 visible accounts, saw %', n; END IF;
  SELECT count(*) INTO n FROM accounts WHERE company_id = '00000000-0000-0000-0000-00000000000b';
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T8b: company B accounts visible'; END IF;
  SELECT count(*) INTO n FROM companies;
  IF n <> 1 THEN RAISE EXCEPTION 'FAIL T8c: expected 1 visible company, saw %', n; END IF;
  BEGIN
    INSERT INTO contacts (company_id, name, is_customer) VALUES ('00000000-0000-0000-0000-00000000000b', 'Sneaky', true);
    RAISE EXCEPTION 'FAIL T8d: wrote into another company';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T8 tenant isolation (reads and writes)';
END $$;

-- T8e: with no company selected, the user still sees only companies they belong to
SELECT set_config('app.company_id', '', false);
DO $$
DECLARE n integer;
BEGIN
  SELECT count(*) INTO n FROM companies;
  IF n <> 1 THEN RAISE EXCEPTION 'FAIL T8e: expected 1 company in switcher, saw %', n; END IF;
  SELECT count(*) INTO n FROM journal_entries;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T8f: ledger visible without a selected company'; END IF;
  RAISE NOTICE 'PASS T8e company switcher shows only own companies; no ledger without a company';
END $$;
SELECT set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false);

-- T9: a posted document is frozen except status and amount_due; paid states follow amount_due
DO $$
DECLARE st text; v integer;
BEGIN
  INSERT INTO contacts (id, company_id, name, is_customer, phone, email, website)
    VALUES ('00000000-0000-0000-0000-0000000000c1', app_company_id(), 'Sheger Grand Hotel', true, '+251911000000', 'ap@sheger.example', 'https://sheger.example');
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due)
    VALUES ('00000000-0000-0000-0000-0000000000d1', app_company_id(), 'invoice', 'INV-0001', '00000000-0000-0000-0000-0000000000c1',
            '2026-11-02', 'ETB', 501745, 501745);
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e001'
   WHERE id = '00000000-0000-0000-0000-0000000000d1';
  UPDATE documents SET amount_due = 100000 WHERE id = '00000000-0000-0000-0000-0000000000d1' RETURNING status, lock_version INTO st, v;
  IF st <> 'part_paid' THEN RAISE EXCEPTION 'FAIL T9a: expected part_paid, got %', st; END IF;
  IF v <> 3 THEN RAISE EXCEPTION 'FAIL T9b: lock_version should be 3 after two saves, got %', v; END IF;
  BEGIN
    UPDATE documents SET total = 1 WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T9: posted document total changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  UPDATE documents SET amount_due = total WHERE id = '00000000-0000-0000-0000-0000000000d1' RETURNING status INTO st;
  IF st <> 'posted' THEN RAISE EXCEPTION 'FAIL T9c: expected posted again, got %', st; END IF;
  RAISE NOTICE 'PASS T9 posted document frozen; status follows amount_due; every save bumps lock_version';
END $$;

-- T10: the audit log is append-only
DO $$ BEGIN
  INSERT INTO audit_log (company_id, action, subject_type) VALUES (app_company_id(), 'posted', 'invoice');
  BEGIN
    DELETE FROM audit_log;
    RAISE EXCEPTION 'FAIL T10: audit log row deleted';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T10 audit log append-only';
  END;
END $$;

-- T11: stock moves (the bin card) are append-only
DO $$ BEGIN
  INSERT INTO items (id, company_id, sku, name, type) VALUES ('00000000-0000-0000-0000-0000000000b1', app_company_id(), 'RC-YRG-1K', 'Roasted coffee 1 kg', 'inventory');
  INSERT INTO warehouses (id, company_id, name) VALUES ('00000000-0000-0000-0000-0000000000a1', app_company_id(), 'Bole warehouse');
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, reference) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000a1', '2026-10-01', 'opening', 300, 700, 'Opening'),
    (app_company_id(), '00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000a1', '2026-10-20', 'purchase', 200, 720, 'GRN-0091'),
    (app_company_id(), '00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000a1', '2026-11-02', 'sale', -240, 708, 'INV-2019-0419');
  BEGIN
    UPDATE stock_moves SET quantity = 1;
    RAISE EXCEPTION 'FAIL T11: stock move edited';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T11 stock moves append-only';
  END;
END $$;

-- T12: a posted document cannot change until its current version is saved to history
DO $$ BEGIN
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal)
  VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000d1', 1, 'Coffee', 1, 100, '00000000-0000-0000-0000-000000000a40', 100);
  RAISE EXCEPTION 'FAIL T12a: line added to posted document without a saved version';
EXCEPTION WHEN insufficient_privilege THEN
  RAISE NOTICE 'PASS T12a lines of a posted document frozen until version saved';
END $$;

DO $$ BEGIN
  -- the corrected entry the edit re-posts with
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
    VALUES ('00000000-0000-0000-0000-00000000e012', app_company_id(), 12, '2026-11-02', 'ETB', 'invoice');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e012', 1, '00000000-0000-0000-0000-000000000a11', 400000, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e012', 2, '00000000-0000-0000-0000-000000000a40', 0, 400000);
  BEGIN
    UPDATE documents SET total = 400000, amount_due = 400000, journal_entry_id = '00000000-0000-0000-0000-00000000e012'
     WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T12b: posted document edited without a saved version';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T12b edit refused without a saved version';
  END;

  INSERT INTO document_versions (company_id, document_id, version, journal_entry_id, snapshot, reason, edited_by)
  SELECT app_company_id(), d.id, 1, d.journal_entry_id, to_jsonb(d), 'Customer agreed a lower price', '00000000-0000-0000-0000-0000000000f1'
    FROM documents d WHERE d.id = '00000000-0000-0000-0000-0000000000d1';
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal)
  VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000d1', 1, 'Coffee', 1, 400000, '00000000-0000-0000-0000-000000000a40', 400000);
  UPDATE documents SET total = 400000, amount_due = 400000, journal_entry_id = '00000000-0000-0000-0000-00000000e012'
   WHERE id = '00000000-0000-0000-0000-0000000000d1';
  RAISE NOTICE 'PASS T12c edit allowed once version 1 is saved and re-posted';

  BEGIN
    UPDATE documents SET notes = 'second edit, no new version' WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T12d: second edit skipped history';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T12d every further edit needs its own saved version';
  END;
END $$;

-- T13: document history cannot be changed or deleted
DO $$ BEGIN
  BEGIN
    UPDATE document_versions SET reason = 'rewritten';
    RAISE EXCEPTION 'FAIL T13a: history rewritten';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM document_versions;
    RAISE EXCEPTION 'FAIL T13b: history deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T13 document history append-only';
END $$;

-- T14: the audit log is hash-chained and verifies
DO $$
DECLARE broken bigint;
BEGIN
  INSERT INTO audit_log (company_id, user_id, action, subject_type, before, after, reason)
  VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000f1', 'edited', 'invoice',
          '{"total":"501745.0000"}', '{"total":"400000.0000"}', 'Customer agreed a lower price');
  SELECT audit_log_verify(app_company_id()) INTO broken;
  IF broken IS NOT NULL THEN RAISE EXCEPTION 'FAIL T14a: intact chain reported broken at %', broken; END IF;
  IF EXISTS (SELECT 1 FROM audit_log WHERE company_id = app_company_id() AND row_hash IS NULL) THEN
    RAISE EXCEPTION 'FAIL T14b: audit row without hash';
  END IF;
  RAISE NOTICE 'PASS T14 audit log hash chain intact';
END $$;

-- T16: partitions are reachable only through the parent table (where row-level security applies)
DO $$ BEGIN
  PERFORM 1 FROM journal_lines_p00 LIMIT 1;
  RAISE EXCEPTION 'FAIL T16: a partition was readable directly';
EXCEPTION WHEN insufficient_privilege THEN
  RAISE NOTICE 'PASS T16 partitions not readable directly';
END $$;

-- T17: account_balances equals the ledger lines; the trial balance balances; the app cannot write balances
DO $$
DECLARE n integer; d numeric; c numeric; d2 numeric;
BEGIN
  SELECT count(*) INTO n FROM ledger_verify(app_company_id());
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T17a: ledger_verify reported % problems', n; END IF;
  SELECT sum(debit), sum(credit) INTO d, c FROM trial_balance(app_company_id(), '2026-11-30');
  IF d IS NULL OR d <> c THEN RAISE EXCEPTION 'FAIL T17b: trial balance does not balance (% vs %)', d, c; END IF;
  SELECT sum(debit) INTO d2 FROM journal_lines;
  IF d <> d2 THEN RAISE EXCEPTION 'FAIL T17c: trial balance % differs from ledger lines %', d, d2; END IF;
  -- part month: nothing is dated before 2 Nov, everything by 2 Nov
  SELECT coalesce(sum(debit), 0) INTO d FROM trial_balance(app_company_id(), '2026-11-01');
  IF d <> 0 THEN RAISE EXCEPTION 'FAIL T17d: as-at 1 Nov should be empty, got %', d; END IF;
  SELECT sum(debit) INTO d FROM trial_balance(app_company_id(), '2026-11-02');
  IF d <> d2 THEN RAISE EXCEPTION 'FAIL T17e: as-at 2 Nov should include every line'; END IF;
  BEGIN
    UPDATE account_balances SET debit = debit + 1;
    RAISE EXCEPTION 'FAIL T17f: application changed account_balances';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T17 balances table equals ledger; trial balance balances at month end and mid month';
END $$;

-- T18: lines cannot be added to an entry posted in an earlier transaction
DO $$ BEGIN
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e012', 3, '00000000-0000-0000-0000-000000000a11', 5, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e012', 4, '00000000-0000-0000-0000-000000000a40', 0, 5);
  RAISE EXCEPTION 'FAIL T18: lines added to an already posted entry';
EXCEPTION WHEN insufficient_privilege THEN
  RAISE NOTICE 'PASS T18 posted entries accept no further lines';
END $$;

-- T19: state machines refuse illegal moves
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, number, contact_id, issue_date, currency, status, total, amount_due)
      VALUES (app_company_id(), 'invoice', 'INV-BAD', '00000000-0000-0000-0000-0000000000c1', '2026-11-02', 'ETB', 'paid', 10, 0);
    RAISE EXCEPTION 'FAIL T19a: invoice created as paid';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due)
    VALUES ('00000000-0000-0000-0000-0000000000d2', app_company_id(), 'invoice', 'INV-0002', '00000000-0000-0000-0000-0000000000c1', '2026-11-02', 'ETB', 1000, 1000);
  BEGIN
    UPDATE documents SET status = 'paid' WHERE id = '00000000-0000-0000-0000-0000000000d2';
    RAISE EXCEPTION 'FAIL T19b: draft jumped to paid';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET status = 'sent' WHERE id = '00000000-0000-0000-0000-0000000000d2';
    RAISE EXCEPTION 'FAIL T19c: invoice used a quote state';
  EXCEPTION WHEN check_violation THEN NULL; END;

  UPDATE documents SET status = 'void' WHERE id = '00000000-0000-0000-0000-0000000000d2';
  BEGIN
    UPDATE documents SET status = 'draft' WHERE id = '00000000-0000-0000-0000-0000000000d2';
    RAISE EXCEPTION 'FAIL T19d: void document revived';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;

  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due)
    VALUES ('00000000-0000-0000-0000-0000000000d3', app_company_id(), 'quote', 'QUO-0001', '00000000-0000-0000-0000-0000000000c1', '2026-11-02', 'ETB', 1000, 1000);
  UPDATE documents SET status = 'sent' WHERE id = '00000000-0000-0000-0000-0000000000d3';
  BEGIN
    UPDATE documents SET status = 'posted' WHERE id = '00000000-0000-0000-0000-0000000000d3';
    RAISE EXCEPTION 'FAIL T19e: quote was posted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T19 document state machines refuse illegal moves (5 cases)';
END $$;

-- T20: payments follow the invoice state machine: allocation, over-allocation, void while paid, reversal
DO $$
DECLARE st text; due numeric;
BEGIN
  INSERT INTO accounts (id, company_id, code, name, type, subtype) VALUES
    ('00000000-0000-0000-0000-000000000a10', app_company_id(), '1000', 'Bank', 'asset', 'bank');
  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount)
    VALUES ('00000000-0000-0000-0000-0000000000e1', app_company_id(), 'in', '00000000-0000-0000-0000-0000000000c1',
            '00000000-0000-0000-0000-000000000a10', '2026-11-05', 'bank_transfer', 'ETB', 400000);
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount)
      VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e1', '00000000-0000-0000-0000-0000000000d1', 400000.01);
    RAISE EXCEPTION 'FAIL T20a: allocation above the open amount accepted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount)
      VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e1', '00000000-0000-0000-0000-0000000000d2', 10);
    RAISE EXCEPTION 'FAIL T20b: payment allocated to a void invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO payment_allocations (company_id, payment_id, document_id, amount)
    VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e1', '00000000-0000-0000-0000-0000000000d1', 150000);
  SELECT status, amount_due INTO st, due FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d1';
  IF st <> 'part_paid' OR due <> 250000 THEN RAISE EXCEPTION 'FAIL T20c: expected part_paid 250000, got % %', st, due; END IF;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T20d: invoice voided while a payment is allocated';
  EXCEPTION WHEN check_violation THEN NULL; END;

  DELETE FROM payment_allocations WHERE payment_id = '00000000-0000-0000-0000-0000000000e1';
  UPDATE payments SET status = 'reversed' WHERE id = '00000000-0000-0000-0000-0000000000e1';
  SELECT status INTO st FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d1';
  IF st <> 'posted' THEN RAISE EXCEPTION 'FAIL T20e: reversing the payment should reopen the invoice, got %', st; END IF;
  BEGIN
    UPDATE payments SET status = 'posted' WHERE id = '00000000-0000-0000-0000-0000000000e1';
    RAISE EXCEPTION 'FAIL T20f: reversed payment revived';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T20 payment allocation guards (over-allocation, void invoice, void while paid, reversal reopens)';
END $$;

-- T21: an invoice cleared by the tax authority cannot be voided or edited; cleared is final
DO $$ BEGIN
  INSERT INTO einvoice_submissions (id, company_id, document_id, authority)
    VALUES ('00000000-0000-0000-0000-0000000000e9', app_company_id(), '00000000-0000-0000-0000-0000000000d1', 'SANDBOX');
  UPDATE einvoice_submissions SET status = 'cleared', clearance_number = 'IRN-1' WHERE id = '00000000-0000-0000-0000-0000000000e9';
  BEGIN
    UPDATE einvoice_submissions SET status = 'queued' WHERE id = '00000000-0000-0000-0000-0000000000e9';
    RAISE EXCEPTION 'FAIL T21a: cleared e-invoice re-queued';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T21b: cleared invoice voided';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T21 cleared e-invoice is final; cleared invoice cannot be voided';
END $$;

-- T22: organisations are isolated, and the application cannot change its own subscription status
DO $$
DECLARE n integer;
BEGIN
  SELECT count(*) INTO n FROM organisations;
  IF n <> 1 THEN RAISE EXCEPTION 'FAIL T22a: expected 1 visible organisation, saw %', n; END IF;
  BEGIN
    UPDATE organisations SET status = 'active';
    RAISE EXCEPTION 'FAIL T22b: application changed the subscription status';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T22 organisations isolated; subscription status is not writable by the application';
END $$;

-- T23: a suspended subscription or an archived company is read-only
RESET ROLE;
UPDATE organisations SET status = 'active'    WHERE id = '00000000-0000-0000-0000-0000000000a0';
UPDATE organisations SET status = 'past_due'  WHERE id = '00000000-0000-0000-0000-0000000000a0';
UPDATE organisations SET status = 'suspended' WHERE id = '00000000-0000-0000-0000-0000000000a0';
DO $$ BEGIN
  UPDATE organisations SET status = 'trial' WHERE id = '00000000-0000-0000-0000-0000000000a0';
  RAISE EXCEPTION 'FAIL T23a: suspended subscription went back to trial';
EXCEPTION WHEN check_violation THEN NULL; END $$;
SET ROLE ledgerly_web;
DO $$
DECLARE n integer;
BEGIN
  BEGIN
    INSERT INTO journal_entries (company_id, number, entry_date, currency) VALUES (app_company_id(), 23, '2026-11-10', 'ETB');
    RAISE EXCEPTION 'FAIL T23b: posting accepted while suspended';
  EXCEPTION WHEN check_violation THEN NULL; END;
  SELECT count(*) INTO n FROM journal_lines;
  IF n = 0 THEN RAISE EXCEPTION 'FAIL T23c: a suspended company must still be able to read its books'; END IF;
END $$;
RESET ROLE;
UPDATE organisations SET status = 'active' WHERE id = '00000000-0000-0000-0000-0000000000a0';
UPDATE companies SET status = 'live'     WHERE id = '00000000-0000-0000-0000-00000000000a';
UPDATE companies SET status = 'archived' WHERE id = '00000000-0000-0000-0000-00000000000a';
SET ROLE ledgerly_web;
DO $$ BEGIN
  INSERT INTO journal_entries (company_id, number, entry_date, currency) VALUES (app_company_id(), 23, '2026-11-10', 'ETB');
  RAISE EXCEPTION 'FAIL T23d: posting accepted in an archived company';
EXCEPTION WHEN check_violation THEN
  RAISE NOTICE 'PASS T23 suspended subscription and archived company are read-only (reads still work)';
END $$;
RESET ROLE;
UPDATE companies SET status = 'live' WHERE id = '00000000-0000-0000-0000-00000000000a';
SET ROLE ledgerly_web;

-- T24: tenant context set for one transaction is gone after it (safe behind a connection pooler)
SELECT set_config('app.user_id', '', false), set_config('app.company_id', '', false) \gset ignore_
BEGIN;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true) \gset ignore_
DO $$ BEGIN
  IF (SELECT count(*) FROM journal_lines) = 0 THEN RAISE EXCEPTION 'FAIL T24a: no rows inside the tenant transaction'; END IF;
END $$;
COMMIT;
DO $$ BEGIN
  IF app_company_id() IS NOT NULL OR app_user_id() IS NOT NULL THEN RAISE EXCEPTION 'FAIL T24b: tenant context survived the transaction'; END IF;
  IF (SELECT count(*) FROM journal_lines) + (SELECT count(*) FROM companies) + (SELECT count(*) FROM documents) <> 0 THEN
    RAISE EXCEPTION 'FAIL T24c: rows visible with no tenant context';
  END IF;
  RAISE NOTICE 'PASS T24 transaction-local tenant context leaves nothing behind';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_

RESET ROLE;

-- T15: tampering is detected (simulates someone with database superuser access bypassing the triggers)
ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_changes;
UPDATE audit_log SET after = '{"total":"1.0000"}' WHERE action = 'edited';
ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_changes;
DO $$
DECLARE broken bigint;
BEGIN
  SELECT audit_log_verify('00000000-0000-0000-0000-00000000000a') INTO broken;
  IF broken IS NULL THEN RAISE EXCEPTION 'FAIL T15: tampered audit row not detected'; END IF;
  RAISE NOTICE 'PASS T15 tampered audit row detected at id %', broken;
END $$;

-- T25: ledger_verify catches a balances table that has drifted from the ledger
UPDATE account_balances SET debit = debit + 0.01
 WHERE (company_id, account_id, period) = (SELECT company_id, account_id, period FROM account_balances LIMIT 1);
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM ledger_verify('00000000-0000-0000-0000-00000000000a') WHERE problem LIKE 'balances differ%') THEN
    RAISE EXCEPTION 'FAIL T25: drifted balance not detected';
  END IF;
  RAISE NOTICE 'PASS T25 ledger_verify detects a drifted balance';
END $$;


-- =====================================================================================================
-- Access rules inside a company (008_access_hardening.sql)
-- =====================================================================================================
INSERT INTO organisations (id, name, created_by) VALUES ('00000000-0000-0000-0000-0000000000c0', 'Access Org', '00000000-0000-0000-0000-0000000000f1');
INSERT INTO users (id, name, email, password, mfa_secret) VALUES
  ('00000000-0000-0000-0000-0000000000c1', 'Owner',      'owner@access.test',      'hash-owner', 'secret-owner'),
  ('00000000-0000-0000-0000-0000000000c2', 'Admin',      'admin@access.test',      'hash-admin', NULL),
  ('00000000-0000-0000-0000-0000000000c3', 'Viewer',     'viewer@access.test',     'hash-viewer', NULL),
  ('00000000-0000-0000-0000-0000000000c4', 'Accountant', 'accountant@access.test', 'hash-acc', NULL),
  ('00000000-0000-0000-0000-0000000000c5', 'Invited',    'invited@access.test',    'hash-inv', NULL),
  ('00000000-0000-0000-0000-0000000000c6', 'Stranger',   'stranger@access.test',   'hash-str', 'secret-stranger');
INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency, books_closed_until) VALUES
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c0', 'Access Co', 'ET', 'ETB', '2026-06-30');
INSERT INTO memberships (company_id, user_id, role, status) VALUES
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c1', 'owner', 'active'),
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c2', 'admin', 'active'),
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c3', 'viewer', 'active'),
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c4', 'accountant', 'active'),
  ('00000000-0000-0000-0000-00000000000c', '00000000-0000-0000-0000-0000000000c5', 'sales', 'invited');

SET ROLE ledgerly_web;
SELECT set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T26: a viewer cannot make themselves an owner, add a member, or remove one
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false) \gset ignore_
DO $$
DECLARE n int;
BEGIN
  BEGIN
    UPDATE memberships SET role = 'owner' WHERE user_id = app_user_id() AND company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T26a: a viewer promoted themselves';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO memberships (company_id, user_id, role) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000c6', 'admin');
    RAISE EXCEPTION 'FAIL T26b: a viewer added a member';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  UPDATE memberships SET role = 'viewer' WHERE user_id = '00000000-0000-0000-0000-0000000000c2';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T26c: a viewer changed another member'; END IF;
  DELETE FROM memberships WHERE user_id = '00000000-0000-0000-0000-0000000000c2';
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T26d: a viewer removed a member'; END IF;
  RAISE NOTICE 'PASS T26 a member without admin rights cannot change users or roles';
END $$;

-- T27: an admin manages members but cannot grant, change or remove the owner role
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c2', false) \gset ignore_
DO $$ BEGIN
  UPDATE memberships SET role = 'bookkeeper' WHERE user_id = '00000000-0000-0000-0000-0000000000c3' AND company_id = app_company_id();
  BEGIN
    UPDATE memberships SET role = 'owner' WHERE user_id = app_user_id() AND company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T27a: an admin made themselves owner';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE memberships SET status = 'deactivated' WHERE user_id = '00000000-0000-0000-0000-0000000000c1' AND company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T27b: an admin deactivated the owner';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO memberships (company_id, user_id, role) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000c6', 'owner');
    RAISE EXCEPTION 'FAIL T27c: an admin added an owner';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T27 an admin manages members but not the owner role';
END $$;

-- T28: the last active owner cannot be removed or demoted, even by themselves
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    UPDATE memberships SET role = 'viewer' WHERE user_id = app_user_id() AND company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T28a: the last owner was demoted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM memberships WHERE user_id = app_user_id() AND company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T28b: the last owner was removed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T28 a company always keeps an active owner';
END $$;

-- T29: an invited user can accept their own invitation and change nothing else
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c5', false),
       set_config('app.company_id', '', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    UPDATE memberships SET status = 'active', role = 'admin' WHERE user_id = app_user_id();
    RAISE EXCEPTION 'FAIL T29a: an invited user chose their own role';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  UPDATE memberships SET status = 'active' WHERE user_id = app_user_id();
  IF (SELECT status FROM memberships WHERE user_id = app_user_id()) <> 'active' THEN
    RAISE EXCEPTION 'FAIL T29b: the invitation could not be accepted';
  END IF;
  RAISE NOTICE 'PASS T29 an invited user can only accept the invitation';
END $$;
SELECT set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T30: closing a period is for accountants; reopening it and company settings are for owner and admin
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c4', false) \gset ignore_
DO $$ BEGIN
  UPDATE companies SET books_closed_until = '2026-07-31' WHERE id = app_company_id();
  BEGIN
    UPDATE companies SET books_closed_until = '2026-01-31' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T30a: an accountant reopened a closed period';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE companies SET books_closed_until = NULL WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T30b: an accountant removed the period lock';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE companies SET tin = '0000000000', books_closed_until = '2026-08-31' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T30c: an accountant changed company settings';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    UPDATE companies SET books_closed_until = '2026-12-31' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T30d: a bookkeeper changed the period lock';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE organisations SET name = 'Renamed' WHERE id = '00000000-0000-0000-0000-0000000000c0';
    IF (SELECT name FROM organisations WHERE id = '00000000-0000-0000-0000-0000000000c0') = 'Renamed' THEN
      RAISE EXCEPTION 'FAIL T30e: a bookkeeper renamed the organisation';
    END IF;
  END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c2', false) \gset ignore_
DO $$ BEGIN
  UPDATE companies SET books_closed_until = '2026-06-30', trading_name = 'Access' WHERE id = app_company_id();
  BEGIN
    UPDATE companies SET organisation_id = '00000000-0000-0000-0000-0000000000a9' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T30f: a company was moved to another organisation';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T30 period reopening and company settings need an owner or admin';
END $$;

-- T31: only the organisation's creator or an owner/admin of one of its companies can add a company to it
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false),
       set_config('app.company_id', '00000000-0000-0000-0000-0000000000cd', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency)
      VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000c0', 'Sneaked in', 'ET', 'ETB');
    RAISE EXCEPTION 'FAIL T31a: a bookkeeper added a company to the organisation';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false) \gset ignore_
DO $$ BEGIN
  INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency)
    VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000c0', 'Second Co', 'ET', 'ETB');
  INSERT INTO memberships (company_id, user_id, role, status) VALUES (app_company_id(), app_user_id(), 'owner', 'active');
  RAISE NOTICE 'PASS T31 adding a company to an organisation needs its creator, owner or admin';
END $$;
-- ...and once a company has an owner, nobody else can use the first-owner rule to join it
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c6', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO memberships (company_id, user_id, role, status) VALUES (app_company_id(), app_user_id(), 'owner', 'active');
    RAISE EXCEPTION 'FAIL T31b: a stranger joined a company as owner';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;

-- T32: user records are visible only to the user and to members of the company in the context
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_
DO $$
DECLARE n int;
BEGIN
  IF EXISTS (SELECT 1 FROM users WHERE email IN ('stranger@access.test', 'hana@example.test')) THEN
    RAISE EXCEPTION 'FAIL T32a: a user outside the company is visible';
  END IF;
  IF (SELECT count(*) FROM users) <> 5 THEN RAISE EXCEPTION 'FAIL T32b: expected the 5 company members, saw %', (SELECT count(*) FROM users); END IF;
  UPDATE users SET password = 'stolen' WHERE id <> app_user_id();
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T32c: another user''s password was changed'; END IF;
  BEGIN
    DELETE FROM users WHERE id = '00000000-0000-0000-0000-0000000000c5';
    RAISE EXCEPTION 'FAIL T32d: a user was deleted by the application';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    PERFORM app_user_id_for_invite('stranger@access.test');
    RAISE EXCEPTION 'FAIL T32e: a bookkeeper looked up a user by email';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T32 user records are visible only to the user and their company';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false) \gset ignore_
DO $$ BEGIN
  IF app_user_id_for_invite('STRANGER@access.test') IS DISTINCT FROM '00000000-0000-0000-0000-0000000000c6'::uuid THEN
    RAISE EXCEPTION 'FAIL T32f: an owner could not look up a user to invite';
  END IF;
END $$;

-- T33: the audit chain can be verified only for the current company
DO $$ BEGIN
  PERFORM audit_log_verify(app_company_id());
  BEGIN
    PERFORM audit_log_verify('00000000-0000-0000-0000-00000000000a');
    RAISE EXCEPTION 'FAIL T33: another company''s audit chain was checked';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T33 the audit chain check is limited to the current company';
  END;
END $$;

-- T36: recovery codes belong to one user; the authenticator cannot be "confirmed" without a secret (009)
DO $$
DECLARE n int;
BEGIN
  INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (app_user_id(), 'hash-of-c1-code');
  BEGIN
    INSERT INTO user_recovery_codes (user_id, code_hash) VALUES ('00000000-0000-0000-0000-0000000000c3', 'planted');
    RAISE EXCEPTION 'FAIL T36a: a recovery code was planted on another user';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE users SET mfa_secret = NULL, mfa_confirmed_at = now() WHERE id = app_user_id();
    RAISE EXCEPTION 'FAIL T36b: the authenticator was marked confirmed without a secret';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false) \gset ignore_
DO $$
DECLARE n int;
BEGIN
  IF EXISTS (SELECT 1 FROM user_recovery_codes) THEN RAISE EXCEPTION 'FAIL T36c: another user''s recovery codes are visible'; END IF;
  UPDATE user_recovery_codes SET used_at = now();
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T36d: another user''s recovery code was used up'; END IF;
  DELETE FROM user_recovery_codes;
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T36e: another user''s recovery codes were deleted'; END IF;
  RAISE NOTICE 'PASS T36 recovery codes are readable and usable only by their own user';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false) \gset ignore_

-- T37: emailed one-time links belong to one user and only known kinds exist (010)
DO $$ BEGIN
  INSERT INTO user_email_tokens (user_id, purpose, token_hash, expires_at) VALUES (app_user_id(), 'reset_password', 'hash-of-c1-link', now() + interval '1 hour');
  BEGIN
    INSERT INTO user_email_tokens (user_id, purpose, token_hash, expires_at)
      VALUES ('00000000-0000-0000-0000-0000000000c3', 'reset_password', 'planted-link', now() + interval '1 hour');
    RAISE EXCEPTION 'FAIL T37a: a reset link was planted on another user';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO user_email_tokens (user_id, purpose, token_hash, expires_at) VALUES (app_user_id(), 'become_admin', 'other-kind', now());
    RAISE EXCEPTION 'FAIL T37b: an unknown kind of link was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c3', false) \gset ignore_
DO $$
DECLARE n int;
BEGIN
  IF EXISTS (SELECT 1 FROM user_email_tokens) THEN RAISE EXCEPTION 'FAIL T37c: another user''s links are visible'; END IF;
  UPDATE user_email_tokens SET used_at = now();
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T37d: another user''s link was used up'; END IF;
  RAISE NOTICE 'PASS T37 emailed one-time links are readable and usable only by their own user';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false) \gset ignore_

-- T34: the application role cannot create objects, read partitions, or become another role
DO $$ BEGIN
  BEGIN
    CREATE TABLE sneaky (id int);
    RAISE EXCEPTION 'FAIL T34a: the application role created a table';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    ALTER TABLE users DISABLE ROW LEVEL SECURITY;
    RAISE EXCEPTION 'FAIL T34b: the application role switched off row-level security';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE state_transitions SET to_state = 'draft';
    RAISE EXCEPTION 'FAIL T34c: the application role rewrote the state machines';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T34 the application role cannot change the schema or the rules';
END $$;

RESET ROLE;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ledgerly_web' AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb)) THEN
    RAISE EXCEPTION 'FAIL T35: the application login role has elevated rights';
  END IF;
  IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND NOT c.relrowsecurity
                AND c.relname NOT IN ('state_transitions')) THEN
    RAISE EXCEPTION 'FAIL T35: a table has no row-level security: %',
      (SELECT string_agg(c.relname, ', ') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND NOT c.relrowsecurity
          AND c.relname NOT IN ('state_transitions'));
  END IF;
  RAISE NOTICE 'PASS T35 every table has row-level security and the application role has no elevated rights';
END $$;

\echo 'All ledger guard tests passed.'

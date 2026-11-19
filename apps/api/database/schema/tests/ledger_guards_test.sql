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
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
      VALUES ('00000000-0000-0000-0000-00000000e002', app_company_id(), 2, '2026-11-02', 'ETB', 'test');
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
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
      VALUES ('00000000-0000-0000-0000-00000000e003', app_company_id(), 3, '2026-11-02', 'ETB', 'test');
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
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
      VALUES ('00000000-0000-0000-0000-00000000e004', app_company_id(), 4, '2026-11-02', 'ETB', 'test');
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
    INSERT INTO journal_entries (company_id, number, entry_date, currency, source_type)
      VALUES (app_company_id(), 6, '2026-09-15', 'ETB', 'test');
    RAISE EXCEPTION 'FAIL T6: entry in closed period accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'PASS T6 closed period refused';
  END;
END $$;

-- T7: a line cannot use another company's account
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
      VALUES ('00000000-0000-0000-0000-00000000e007', app_company_id(), 7, '2026-11-02', 'ETB', 'test');
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
    VALUES ('00000000-0000-0000-0000-0000000000d3', app_company_id(), 'quote', 'QUO-0001', '00000000-0000-0000-0000-0000000000c1', '2026-11-02', 'ETB', 1000, 0);   -- a quote owes nothing (056)
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
    INSERT INTO journal_entries (company_id, number, entry_date, currency, source_type) VALUES (app_company_id(), 23, '2026-11-10', 'ETB', 'test');
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
  INSERT INTO journal_entries (company_id, number, entry_date, currency, source_type) VALUES (app_company_id(), 23, '2026-11-10', 'ETB', 'test');
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

-- T38: the foundation of the books is fixed once anything is posted (011); company A has posted entries since T1
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false) \gset ignore_
SELECT set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  UPDATE companies SET legal_form = 'private_limited', city = 'Addis Ababa', trading_name = 'A Trading' WHERE id = app_company_id();
  IF NOT FOUND THEN RAISE EXCEPTION 'FAIL T38a: the owner could not change the profile'; END IF;
  BEGIN
    UPDATE companies SET base_currency = 'USD' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T38b: the base currency changed after posting';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE companies SET fiscal_year_start_month = 7 WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T38c: the fiscal year start changed after posting';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE companies SET conversion_date = '2026-06-30' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T38d: the conversion date changed after posting';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE companies SET legal_form = 'kingdom' WHERE id = app_company_id();
    RAISE EXCEPTION 'FAIL T38e: an unknown legal form was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
RESET ROLE;
DO $$ BEGIN
  BEGIN
    UPDATE companies SET calendar = 'ethiopian' WHERE id = '00000000-0000-0000-0000-00000000000a';
    RAISE EXCEPTION 'FAIL T38f: the owner role changed the calendar after posting';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- Nothing posted yet: the foundation may still change.
  INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency)
    VALUES ('00000000-0000-0000-0000-0000000000d8', '00000000-0000-0000-0000-0000000000a0', 'Company D8', 'ET', 'ETB');
  UPDATE companies SET base_currency = 'USD', fiscal_year_start_month = 7, calendar = 'ethiopian', conversion_date = '2026-07-07'
   WHERE id = '00000000-0000-0000-0000-0000000000d8';
  DELETE FROM companies WHERE id = '00000000-0000-0000-0000-0000000000d8';
  RAISE NOTICE 'PASS T38 the foundation of the books is fixed once anything is posted, for every role';
END $$;
SET ROLE ledgerly_web;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T39: foreign currencies and exchange rates belong to one company, are never the base currency, never deleted (012)
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  INSERT INTO company_currencies (company_id, currency) VALUES (app_company_id(), 'USD');
  INSERT INTO exchange_rates (company_id, currency, rate_date, rate) VALUES (app_company_id(), 'USD', '2026-11-02', 134.523333);
  BEGIN
    INSERT INTO company_currencies (company_id, currency) VALUES (app_company_id(), 'ETB');
    RAISE EXCEPTION 'FAIL T39a: the base currency was added as a foreign currency';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO exchange_rates (company_id, currency, rate_date, rate) VALUES (app_company_id(), 'ETB', '2026-11-02', 1);
    RAISE EXCEPTION 'FAIL T39b: a rate was stored for the base currency';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO exchange_rates (company_id, currency, rate_date, rate) VALUES (app_company_id(), 'EUR', '2026-11-02', 0);
    RAISE EXCEPTION 'FAIL T39c: a zero rate was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO exchange_rates (company_id, currency, rate_date, rate) VALUES (app_company_id(), 'USD', '2026-11-02', 135);
    RAISE EXCEPTION 'FAIL T39d: two rates were stored for one day';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    DELETE FROM exchange_rates;
    RAISE EXCEPTION 'FAIL T39e: a rate was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE exchange_rates SET currency = 'EUR';
    RAISE EXCEPTION 'FAIL T39f: a rate was moved to another currency';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', false) \gset ignore_
DO $$
DECLARE n int;
BEGIN
  IF EXISTS (SELECT 1 FROM exchange_rates) OR EXISTS (SELECT 1 FROM company_currencies) THEN
    RAISE EXCEPTION 'FAIL T39g: company B sees company A''s currencies or rates';
  END IF;
  UPDATE exchange_rates SET rate = 1;
  GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FAIL T39h: company B changed company A''s rate'; END IF;
  BEGIN
    INSERT INTO exchange_rates (company_id, currency, rate_date, rate) VALUES ('00000000-0000-0000-0000-00000000000a', 'EUR', '2026-11-02', 150);
    RAISE EXCEPTION 'FAIL T39i: company B planted a rate in company A';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T39 currencies and rates belong to one company, are never the base currency and are never deleted';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T40: a posted document has a number that never changes; numbering settings belong to one company (013)
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$
DECLARE posted constant uuid := '00000000-0000-0000-0000-0000000000d4';
BEGIN
  -- A posted invoice of its own (not registered with the tax authority, so the edit path is open).
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
    VALUES ('00000000-0000-0000-0000-00000000e041', app_company_id(), 9041, '2027-06-30', 'ETB', 'invoice');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e041', 1, '00000000-0000-0000-0000-000000000a11', 100, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e041', 2, '00000000-0000-0000-0000-000000000a40', 0, 100);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due)
    VALUES (posted, app_company_id(), 'invoice', 'INV-T40', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', 100, 100);
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e041' WHERE id = posted;
  -- Take the allowed edit path: current version saved, re-posted with a new entry. Only the number rule can refuse.
  INSERT INTO document_versions (company_id, document_id, version, journal_entry_id, snapshot, reason, edited_by)
  SELECT app_company_id(), d.id, 1, d.journal_entry_id, to_jsonb(d), 'T40', app_user_id() FROM documents d WHERE d.id = posted;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type)
    VALUES ('00000000-0000-0000-0000-00000000e040', app_company_id(), 9040, '2027-06-30', 'ETB', 'invoice');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e040', 1, '00000000-0000-0000-0000-000000000a11', 100, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e040', 2, '00000000-0000-0000-0000-000000000a40', 0, 100);
  BEGIN
    UPDATE documents SET number = 'RENUMBERED', journal_entry_id = '00000000-0000-0000-0000-00000000e040' WHERE id = posted;
    RAISE EXCEPTION 'FAIL T40a: a posted number changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET number = NULL, journal_entry_id = '00000000-0000-0000-0000-00000000e040' WHERE id = posted;
    RAISE EXCEPTION 'FAIL T40b: a posted document lost its number';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- The same edit keeping the number is allowed: the rule is about the number only.
  UPDATE documents SET notes = 'T40', journal_entry_id = '00000000-0000-0000-0000-00000000e040' WHERE id = posted;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, status) VALUES
      (app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', 'draft');
  EXCEPTION WHEN others THEN RAISE EXCEPTION 'FAIL T40f: a draft without a number was refused (%)', SQLERRM; END;
  INSERT INTO document_numbering (company_id, doc_type, prefix) VALUES (app_company_id(), 'invoice', 'FV/');
  BEGIN
    INSERT INTO document_numbering (company_id, doc_type, prefix) VALUES (app_company_id(), 'credit_note', 'no spaces');
    RAISE EXCEPTION 'FAIL T40c: a prefix with spaces was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', false) \gset ignore_
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM document_numbering) THEN RAISE EXCEPTION 'FAIL T40d: company B sees company A''s numbering'; END IF;
  BEGIN
    INSERT INTO document_numbering (company_id, doc_type) VALUES ('00000000-0000-0000-0000-00000000000a', 'bill');
    RAISE EXCEPTION 'FAIL T40e: company B changed company A''s numbering';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T40 a posted number never changes and numbering belongs to one company';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T41: a line names only its own company's locations, classes and projects, of the right kind (014)
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', false) \gset ignore_
INSERT INTO dimensions (id, company_id, kind, code, name)
  VALUES ('00000000-0000-0000-0000-000000000d43', app_company_id(), 'location', 'NBO', 'Nairobi');
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$
DECLARE n int := 9410;
  line_ok boolean;
BEGIN
  IF EXISTS (SELECT 1 FROM dimensions) THEN RAISE EXCEPTION 'FAIL T41a: company A sees company B''s dimensions'; END IF;
  INSERT INTO dimensions (id, company_id, kind, code, name) VALUES
    ('00000000-0000-0000-0000-000000000d41', app_company_id(), 'location', 'HQ', 'Head office'),
    ('00000000-0000-0000-0000-000000000d42', app_company_id(), 'class', 'RETAIL', 'Retail');

  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e410', app_company_id(), 9410, '2027-06-30', 'ETB', 'test');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, location_id, class_id) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e410', 1, '00000000-0000-0000-0000-000000000a11', 10, 0,
     '00000000-0000-0000-0000-000000000d41', '00000000-0000-0000-0000-000000000d42'),
    (app_company_id(), '00000000-0000-0000-0000-00000000e410', 2, '00000000-0000-0000-0000-000000000a40', 0, 10, NULL, NULL);

  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e411', app_company_id(), 9411, '2027-06-30', 'ETB', 'test');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, location_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e411', 1, '00000000-0000-0000-0000-000000000a11', 10, 0, '00000000-0000-0000-0000-000000000d43');
    RAISE EXCEPTION 'FAIL T41b: a line named company B''s location';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e412', app_company_id(), 9412, '2027-06-30', 'ETB', 'test');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, location_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e412', 1, '00000000-0000-0000-0000-000000000a11', 10, 0, '00000000-0000-0000-0000-000000000d42');
    RAISE EXCEPTION 'FAIL T41c: a class was used as a location';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, location_id) VALUES
      (app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', '00000000-0000-0000-0000-000000000d43');
    RAISE EXCEPTION 'FAIL T41d: a document named company B''s location';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;

  -- Turned off: still valid on old entries, so they can be reversed.
  UPDATE dimensions SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000d41';
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e413', app_company_id(), 9413, '2027-06-30', 'ETB', 'test');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, location_id) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e413', 1, '00000000-0000-0000-0000-000000000a40', 10, 0, '00000000-0000-0000-0000-000000000d41'),
    (app_company_id(), '00000000-0000-0000-0000-00000000e413', 2, '00000000-0000-0000-0000-000000000a11', 0, 10, '00000000-0000-0000-0000-000000000d41');

  BEGIN
    UPDATE dimensions SET code = 'HQ2' WHERE id = '00000000-0000-0000-0000-000000000d41';
    RAISE EXCEPTION 'FAIL T41e: a dimension code changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM dimensions;
    RAISE EXCEPTION 'FAIL T41f: a dimension was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T41 lines name only their own company''s dimensions, of the right kind; dimensions are never deleted';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T42: contacts are archived, never deleted; a tax exemption names its reason (015)
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    DELETE FROM contacts WHERE id = '00000000-0000-0000-0000-0000000000c1';
    RAISE EXCEPTION 'FAIL T42a: a contact was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE contacts SET tax_exempt = true WHERE id = '00000000-0000-0000-0000-0000000000c1';
    RAISE EXCEPTION 'FAIL T42b: a tax exemption without a reason was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE contacts SET credit_limit = -1 WHERE id = '00000000-0000-0000-0000-0000000000c1';
    RAISE EXCEPTION 'FAIL T42c: a negative credit limit was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE contacts SET is_active = false, tax_exempt = true, tax_exempt_reason = 'Embassy (diplomatic exemption)'
   WHERE id = '00000000-0000-0000-0000-0000000000c1';
  IF NOT FOUND THEN RAISE EXCEPTION 'FAIL T42d: a contact could not be archived'; END IF;
  UPDATE contacts SET is_active = true, tax_exempt = false, tax_exempt_reason = NULL WHERE id = '00000000-0000-0000-0000-0000000000c1';
  RAISE NOTICE 'PASS T42 contacts are archived, never deleted; an exemption names its reason';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T43: a payment is never changed or deleted; it settles only its own customer's invoices in its currency, and never
-- more than it brought in (016)
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  INSERT INTO contacts (id, company_id, name, is_customer) VALUES ('00000000-0000-0000-0000-0000000000c4', app_company_id(), 'Other customer', true);
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e430', app_company_id(), 9430, '2027-06-30', 'ETB', 'invoice');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e430', 1, '00000000-0000-0000-0000-000000000a11', 1000, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e430', 2, '00000000-0000-0000-0000-000000000a40', 0, 1000);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due) VALUES
    ('00000000-0000-0000-0000-0000000000d6', app_company_id(), 'invoice', 'INV-T43', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', 1000, 1000);
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e430' WHERE id = '00000000-0000-0000-0000-0000000000d6';
  -- 600 received + 30 withheld settles at most 630.
  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount, withholding) VALUES
    ('00000000-0000-0000-0000-0000000000e3', app_company_id(), 'in', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 600, 30),
    ('00000000-0000-0000-0000-0000000000e4', app_company_id(), 'in', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 50, 0);

  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e3', '00000000-0000-0000-0000-0000000000d6', 630.01);
    RAISE EXCEPTION 'FAIL T43a: a payment settled more than it brought in';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e4', '00000000-0000-0000-0000-0000000000d6', 10);
    RAISE EXCEPTION 'FAIL T43b: one customer''s payment settled another customer''s invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e3', '00000000-0000-0000-0000-0000000000d6', 630);
  IF (SELECT amount_due FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d6') <> 370 THEN
    RAISE EXCEPTION 'FAIL T43c: the invoice did not show 370 still due';
  END IF;
  BEGIN
    UPDATE payment_allocations SET amount = 1 WHERE payment_id = '00000000-0000-0000-0000-0000000000e3';
    RAISE EXCEPTION 'FAIL T43d: an allocation was edited';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE payments SET amount = 700 WHERE id = '00000000-0000-0000-0000-0000000000e3';
    RAISE EXCEPTION 'FAIL T43e: a recorded payment''s amount changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM payments WHERE id = '00000000-0000-0000-0000-0000000000e4';
    RAISE EXCEPTION 'FAIL T43f: a payment was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  -- Reversal: the allocations go (006 gives the amount back), then the payment is marked reversed.
  DELETE FROM payment_allocations WHERE payment_id = '00000000-0000-0000-0000-0000000000e3';
  UPDATE payments SET status = 'reversed' WHERE id = '00000000-0000-0000-0000-0000000000e3';
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e3', '00000000-0000-0000-0000-0000000000d6', 10);
    RAISE EXCEPTION 'FAIL T43g: a reversed payment settled an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T43 payments are never changed or deleted and settle only their own customer''s invoices, never more than they brought in';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T44: a credit note names a posted invoice of its customer, never credits more than it, and its applications move
-- both amounts due and are never changed (017). Uses invoice INV-T43 (1,000.00, open again after T43's reversal).
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'credit_note', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB');
    RAISE EXCEPTION 'FAIL T44a: a credit note without its invoice was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, original_document_id)
      VALUES (app_company_id(), 'credit_note', '00000000-0000-0000-0000-0000000000c4', '2027-06-30', 'ETB', '00000000-0000-0000-0000-0000000000d6');
    RAISE EXCEPTION 'FAIL T44b: a credit note credited another customer''s invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- A posted credit note of 300.00.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e440', app_company_id(), 9440, '2027-06-30', 'ETB', 'credit_note');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e440', 1, '00000000-0000-0000-0000-000000000a40', 300, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e440', 2, '00000000-0000-0000-0000-000000000a11', 0, 300);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due, original_document_id) VALUES
    ('00000000-0000-0000-0000-0000000000d7', app_company_id(), 'credit_note', 'CN-T44', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', 300, 300, '00000000-0000-0000-0000-0000000000d6');
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e440' WHERE id = '00000000-0000-0000-0000-0000000000d7';

  -- A second one of 800.00 would credit 1,100.00 of a 1,000.00 invoice.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e441', app_company_id(), 9441, '2027-06-30', 'ETB', 'credit_note');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e441', 1, '00000000-0000-0000-0000-000000000a40', 800, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e441', 2, '00000000-0000-0000-0000-000000000a11', 0, 800);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due, original_document_id) VALUES
    ('00000000-0000-0000-0000-0000000000d8', app_company_id(), 'credit_note', 'CN-T44B', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', 800, 800, '00000000-0000-0000-0000-0000000000d6');
  BEGIN
    UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e441' WHERE id = '00000000-0000-0000-0000-0000000000d8';
    RAISE EXCEPTION 'FAIL T44c: credit notes credited more than the invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  BEGIN
    INSERT INTO credit_applications (company_id, credit_note_id, invoice_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000d7', '00000000-0000-0000-0000-0000000000d6', 300.01);
    RAISE EXCEPTION 'FAIL T44d: more was applied than the credit note holds';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO credit_applications (company_id, credit_note_id, invoice_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000d7', '00000000-0000-0000-0000-0000000000d6', 200);
  IF (SELECT amount_due FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d6') <> 800
     OR (SELECT (amount_due, status) FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d7') <> (100.00::numeric(20,2), 'part_paid'::text) THEN
    RAISE EXCEPTION 'FAIL T44e: applying 200.00 did not leave 800.00 due on the invoice and 100.00 of credit';
  END IF;
  BEGIN
    UPDATE credit_applications SET amount = 1;
    RAISE EXCEPTION 'FAIL T44f: an application was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM credit_applications;
    RAISE EXCEPTION 'FAIL T44g: an application was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', false) \gset ignore_
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM credit_applications) THEN RAISE EXCEPTION 'FAIL T44h: company B sees company A''s credit applications'; END IF;
  RAISE NOTICE 'PASS T44 credit notes name their invoice, never credit more than it, and their applications move both amounts and stay';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T46: sales documents name a customer and purchase documents a supplier; a supplier's default account is the
-- company's own expense or asset account; a withholding exemption names its certificate (019).
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  INSERT INTO contacts (id, company_id, name, is_vendor) VALUES ('00000000-0000-0000-0000-0000000000c7', app_company_id(), 'Coffee farm', true);
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'bill', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB');
    RAISE EXCEPTION 'FAIL T46a: a bill was stored for a customer who is not a supplier';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c7', '2027-06-30', 'ETB');
    RAISE EXCEPTION 'FAIL T46b: an invoice was stored for a supplier who is not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO documents (company_id, type, contact_id, issue_date, currency, reference) VALUES (app_company_id(), 'bill', '00000000-0000-0000-0000-0000000000c7', '2027-06-30', 'ETB', 'F-1182');
  BEGIN
    UPDATE contacts SET default_expense_account_id = '00000000-0000-0000-0000-000000000a40' WHERE id = '00000000-0000-0000-0000-0000000000c7';
    RAISE EXCEPTION 'FAIL T46c: an income account became a supplier''s default expense account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE contacts SET default_expense_account_id = '00000000-0000-0000-0000-000000000b11' WHERE id = '00000000-0000-0000-0000-0000000000c7';
    RAISE EXCEPTION 'FAIL T46d: another company''s account became a supplier''s default account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE contacts SET withholding_exempt = true WHERE id = '00000000-0000-0000-0000-0000000000c7';
    RAISE EXCEPTION 'FAIL T46e: a withholding exemption was stored without its certificate';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE contacts SET withholding_exempt = true, withholding_exempt_reason = 'Certificate WHT-EX-2027-114' WHERE id = '00000000-0000-0000-0000-0000000000c7';
  RAISE NOTICE 'PASS T46 sales documents name customers, bills name suppliers, and supplier defaults stay in the company';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T47: a payment made is to a supplier and settles that supplier's bills; a payment received is from a customer and
-- settles invoices; nothing is withheld from an exempt supplier (020). Uses supplier c7 (exempt since T46) and a new
-- supplier c8 with a posted bill of 1,000.00.
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  INSERT INTO contacts (id, company_id, name, is_vendor) VALUES ('00000000-0000-0000-0000-0000000000c8', app_company_id(), 'Fuel station', true);
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e470', app_company_id(), 9470, '2027-06-30', 'ETB', 'bill');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e470', 1, '00000000-0000-0000-0000-000000000a40', 1000, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e470', 2, '00000000-0000-0000-0000-000000000a22', 0, 1000);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due) VALUES
    ('00000000-0000-0000-0000-0000000000d9', app_company_id(), 'bill', 'BILL-T47', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', 1000, 1000);
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e470' WHERE id = '00000000-0000-0000-0000-0000000000d9';

  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount) VALUES
      (app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 10);
    RAISE EXCEPTION 'FAIL T47a: a payment was made to a customer who is not a supplier';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount) VALUES
      (app_company_id(), 'in', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 10);
    RAISE EXCEPTION 'FAIL T47b: a payment was received from a supplier who is not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount, withholding) VALUES
      (app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c7', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'bank_transfer', 'ETB', 970, 30);
    RAISE EXCEPTION 'FAIL T47c: tax was withheld from a supplier with an exemption certificate';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- 582 paid + 18 withheld settles 600 of the bill.
  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount, withholding, withholding_certificate) VALUES
    ('00000000-0000-0000-0000-0000000000e7', app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'bank_transfer', 'ETB', 582, 18, 'WHT-0001');
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e7', '00000000-0000-0000-0000-0000000000d6', 10);
    RAISE EXCEPTION 'FAIL T47d: a payment made settled an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e4', '00000000-0000-0000-0000-0000000000d9', 10);
    RAISE EXCEPTION 'FAIL T47e: a payment received settled a bill';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e7', '00000000-0000-0000-0000-0000000000d9', 600);
  IF (SELECT (amount_due, status) FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d9') <> (400.00::numeric(20,2), 'part_paid'::text) THEN
    RAISE EXCEPTION 'FAIL T47f: the bill did not show 400.00 still to pay';
  END IF;
  BEGIN
    UPDATE payments SET withholding_certificate = 'WHT-0002' WHERE id = '00000000-0000-0000-0000-0000000000e7';
    RAISE EXCEPTION 'FAIL T47g: a recorded withholding certificate changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T47 payments made go to suppliers and settle their bills, and nothing is withheld from an exempt supplier';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T48: an expense is paid from the company's own bank, cash or wallet account, names a supplier and leaves nothing to
-- pay; no other document names a paying account (021). Uses supplier c8 (T47).
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'expense', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB');
    RAISE EXCEPTION 'FAIL T48a: an expense was stored without the account it was paid from';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'expense', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', '00000000-0000-0000-0000-000000000a40', 'cash');
    RAISE EXCEPTION 'FAIL T48b: an expense was paid from an income account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'expense', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', '00000000-0000-0000-0000-000000000a10', 'cash');
    RAISE EXCEPTION 'FAIL T48c: an expense was stored for a customer who is not a supplier';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'bill', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', '00000000-0000-0000-0000-000000000a10', 'cash');
    RAISE EXCEPTION 'FAIL T48d: a bill named a paying account';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e480', app_company_id(), 9480, '2027-06-30', 'ETB', 'expense');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e480', 1, '00000000-0000-0000-0000-000000000a40', 250, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e480', 2, '00000000-0000-0000-0000-000000000a10', 0, 250);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due, paid_from_account_id, payment_method) VALUES
    ('00000000-0000-0000-0000-0000000000da', app_company_id(), 'expense', 'EXP-T48', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', 250, 250,
     '00000000-0000-0000-0000-000000000a10', 'cash');
  BEGIN
    UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e480' WHERE id = '00000000-0000-0000-0000-0000000000da';
    RAISE EXCEPTION 'FAIL T48e: a posted expense was left with something to pay';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'posted', amount_due = 0, journal_entry_id = '00000000-0000-0000-0000-00000000e480' WHERE id = '00000000-0000-0000-0000-0000000000da';
  RAISE NOTICE 'PASS T48 expenses are paid from the company''s own money accounts, name a supplier and leave nothing to pay';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T49: a supplier credit names a posted bill of its supplier, never credits more than it, and its applications move both
-- amounts due; a credit note never settles a bill nor a supplier credit an invoice (022). Uses bill BILL-T47 of
-- supplier c8 (1,000.00, 400.00 still to pay after T47) and invoice INV-T43.
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'vendor_credit', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB');
    RAISE EXCEPTION 'FAIL T49a: a supplier credit without its bill was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, original_document_id)
      VALUES (app_company_id(), 'vendor_credit', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', '00000000-0000-0000-0000-0000000000d6');
    RAISE EXCEPTION 'FAIL T49b: a supplier credit named an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- A posted supplier credit of 300.00 on BILL-T47.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e490', app_company_id(), 9490, '2027-06-30', 'ETB', 'vendor_credit');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e490', 1, '00000000-0000-0000-0000-000000000a22', 300, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e490', 2, '00000000-0000-0000-0000-000000000a40', 0, 300);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due, original_document_id) VALUES
    ('00000000-0000-0000-0000-0000000000db', app_company_id(), 'vendor_credit', 'VC-T49', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', 300, 300, '00000000-0000-0000-0000-0000000000d9');
  UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e490' WHERE id = '00000000-0000-0000-0000-0000000000db';

  -- A second one of 800.00 would credit 1,100.00 of a 1,000.00 bill.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e491', app_company_id(), 9491, '2027-06-30', 'ETB', 'vendor_credit');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e491', 1, '00000000-0000-0000-0000-000000000a22', 800, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e491', 2, '00000000-0000-0000-0000-000000000a40', 0, 800);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, total, amount_due, original_document_id) VALUES
    ('00000000-0000-0000-0000-0000000000dc', app_company_id(), 'vendor_credit', 'VC-T49B', '00000000-0000-0000-0000-0000000000c8', '2027-06-30', 'ETB', 800, 800, '00000000-0000-0000-0000-0000000000d9');
  BEGIN
    UPDATE documents SET status = 'posted', journal_entry_id = '00000000-0000-0000-0000-00000000e491' WHERE id = '00000000-0000-0000-0000-0000000000dc';
    RAISE EXCEPTION 'FAIL T49c: supplier credits credited more than the bill';
  EXCEPTION WHEN check_violation THEN NULL; END;

  BEGIN
    INSERT INTO credit_applications (company_id, credit_note_id, invoice_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000db', '00000000-0000-0000-0000-0000000000d6', 10);
    RAISE EXCEPTION 'FAIL T49d: a supplier credit settled an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO credit_applications (company_id, credit_note_id, invoice_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000d7', '00000000-0000-0000-0000-0000000000d9', 10);
    RAISE EXCEPTION 'FAIL T49e: a credit note settled a bill';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO credit_applications (company_id, credit_note_id, invoice_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000db', '00000000-0000-0000-0000-0000000000d9', 300);
  IF (SELECT amount_due FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d9') <> 100
     OR (SELECT (amount_due, status) FROM documents WHERE id = '00000000-0000-0000-0000-0000000000db') <> (0.00::numeric(20,2), 'paid'::text) THEN
    RAISE EXCEPTION 'FAIL T49f: applying 300.00 did not leave 100.00 to pay on the bill and the credit used up';
  END IF;
  RAISE NOTICE 'PASS T49 supplier credits name their bill, never credit more than it, and settle only bills of their supplier';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T50: refunds go back to customers and come back from suppliers, settle only credits, withhold nothing, and never give
-- back more than a credit or a payment still holds (023). Uses credit note CN-T44 (100.00 left), payment e4 (50.00 from
-- customer c4, unused) and supplier c8.
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount) VALUES
      (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 10);
    RAISE EXCEPTION 'FAIL T50a: a refund was paid out to a supplier';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount) VALUES
      (app_company_id(), 'in', 'refund', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 10);
    RAISE EXCEPTION 'FAIL T50b: a refund was received from a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, withholding) VALUES
      (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 10, 1);
    RAISE EXCEPTION 'FAIL T50c: tax was withheld on a refund';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- 50.00 of CN-T44's credit paid back.
  INSERT INTO payments (id, company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount) VALUES
    ('00000000-0000-0000-0000-0000000000e8', app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 50);
  BEGIN
    INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e8', '00000000-0000-0000-0000-0000000000d6', 10);
    RAISE EXCEPTION 'FAIL T50d: a refund settled an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payment_allocations (company_id, payment_id, document_id, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000e8', '00000000-0000-0000-0000-0000000000d7', 50);
  IF (SELECT amount_due FROM documents WHERE id = '00000000-0000-0000-0000-0000000000d7') <> 50 THEN
    RAISE EXCEPTION 'FAIL T50e: the credit note did not show 50.00 left after a refund of 50.00';
  END IF;

  -- The unused 50.00 of payment e4: 60.00 is too much, 30.00 is fine, and 20.00 is then left.
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, refunds_payment_id) VALUES
      (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 60, '00000000-0000-0000-0000-0000000000e4');
    RAISE EXCEPTION 'FAIL T50f: a refund gave back more than the payment still held';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, refunds_payment_id) VALUES
    (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 30, '00000000-0000-0000-0000-0000000000e4');
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, refunds_payment_id) VALUES
      (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 20.01, '00000000-0000-0000-0000-0000000000e4');
    RAISE EXCEPTION 'FAIL T50g: after a refund of 30.00, more than the 20.00 still unused was refunded';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, refunds_payment_id) VALUES
    (app_company_id(), 'out', 'refund', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 20, '00000000-0000-0000-0000-0000000000e4');
  BEGIN
    INSERT INTO payments (company_id, direction, kind, contact_id, account_id, payment_date, method, currency, amount, refunds_payment_id) VALUES
      (app_company_id(), 'in', 'payment', '00000000-0000-0000-0000-0000000000c4', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cash', 'ETB', 5, '00000000-0000-0000-0000-0000000000e4');
    RAISE EXCEPTION 'FAIL T50h: a payment that is not a refund named a payment it refunds';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T50 refunds go to the right side, settle only credits, withhold nothing and never give back more than is held';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T51: stock is costed by the database at weighted average, never goes below zero, moves only stock items of the
-- company, and the balances are written only by the trigger (024).
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ DECLARE m record; b record; BEGIN
  INSERT INTO items (id, company_id, sku, name, type) VALUES
    ('00000000-0000-0000-0000-0000000000b2', app_company_id(), 'GC-SID-1K', 'Green coffee, Sidama (kg)', 'inventory'),
    ('00000000-0000-0000-0000-0000000000b3', app_company_id(), 'SRV-ROAST', 'Roasting service', 'service');
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000b3', '00000000-0000-0000-0000-0000000000a1', '2027-06-30', 'adjustment', 1, 10);
    RAISE EXCEPTION 'FAIL T51a: a service had a stock move';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000a1', '2027-06-30', 'sale', -1, 0);
    RAISE EXCEPTION 'FAIL T51b: stock went below zero';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- 10 at 100.00 and 5 at 130.00: 15 on hand worth 1,650.00, an average of 110.00.
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000a1', '2027-06-01', 'purchase', 10, 100),
    (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000a1', '2027-06-02', 'purchase', 5, 130);
  -- 4 out at whatever unit cost the caller says: the database takes the average.
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000a1', '2027-06-03', 'sale', -4, 1)
    RETURNING amount, unit_cost INTO m;
  IF (m.amount, m.unit_cost) <> (-440.00::numeric(20,2), 110.000000::numeric(20,6)) THEN
    RAISE EXCEPTION 'FAIL T51c: 4 out were not costed at the average of 110.00 (got %, %)', m.amount, m.unit_cost;
  END IF;
  -- The last 11 take all that is left, so nothing is left behind by rounding.
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000a1', '2027-06-04', 'sale', -11, 0)
    RETURNING amount INTO m;
  SELECT quantity, value INTO b FROM stock_balances WHERE item_id = '00000000-0000-0000-0000-0000000000b2';
  IF m.amount <> -1210 OR (b.quantity, b.value) <> (0::numeric(18,2), 0::numeric(20,2)) THEN
    RAISE EXCEPTION 'FAIL T51d: emptying the warehouse did not take all its value (got %, balance %, %)', m.amount, b.quantity, b.value;
  END IF;

  BEGIN
    UPDATE stock_balances SET quantity = 1000;
    RAISE EXCEPTION 'FAIL T51e: the application role changed a stock balance';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000b2', '00000000-0000-0000-0000-0000000000b9', '2027-06-30', 'purchase', 1, 10);
    RAISE EXCEPTION 'FAIL T51f: stock moved into a warehouse that is not the company''s';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  RAISE NOTICE 'PASS T51 stock is costed at weighted average by the database, never goes below zero, and moves only the company''s stock items';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T52: a document's warehouse, a line's item and a stock move's document all stay within the company (025).
-- Company B's warehouse is created as company B first.
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', false) \gset ignore_
INSERT INTO warehouses (id, company_id, name) VALUES ('00000000-0000-0000-0000-0000000000b9', '00000000-0000-0000-0000-00000000000b', 'B store');
INSERT INTO items (id, company_id, name, type) VALUES ('00000000-0000-0000-0000-0000000000b8', '00000000-0000-0000-0000-00000000000b', 'B item', 'inventory');
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, warehouse_id) VALUES
      (app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', '00000000-0000-0000-0000-0000000000b9');
    RAISE EXCEPTION 'FAIL T52a: an invoice named another company''s warehouse';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, warehouse_id) VALUES
    ('00000000-0000-0000-0000-0000000000dd', app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-06-30', 'ETB', '00000000-0000-0000-0000-0000000000a1');
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000dd', 1, 'x', 1, 1, '00000000-0000-0000-0000-000000000a40', 1, '00000000-0000-0000-0000-0000000000b8');
    RAISE EXCEPTION 'FAIL T52b: a line named another company''s item';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000a1', '2027-06-30', 'purchase', 1, 1, '00000000-0000-0000-0000-0000000000d9');
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000b1', '00000000-0000-0000-0000-0000000000a1', '2027-06-30', 'purchase', 1, 1, '00000000-0000-0000-0000-00000000ffff');
    RAISE EXCEPTION 'FAIL T52c: a stock move named a document that is not the company''s';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T52 warehouses, items and stock moves on documents stay within the company';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T53: an account with entries keeps its type, kind and currency; bank details are an object (026). Uses bank account
-- a10 of company A, which has entries since T13.
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_
DO $$ BEGIN
  BEGIN
    UPDATE accounts SET currency = 'USD' WHERE id = '00000000-0000-0000-0000-000000000a10';
    RAISE EXCEPTION 'FAIL T53a: an account with entries changed its currency';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET type = 'expense', subtype = NULL WHERE id = '00000000-0000-0000-0000-000000000a10';
    RAISE EXCEPTION 'FAIL T53b: an account with entries changed its type';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET bank_details = '[]' WHERE id = '00000000-0000-0000-0000-000000000a10';
    RAISE EXCEPTION 'FAIL T53c: bank details were not an object';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE accounts SET name = 'Bank (CBE Bole)', bank_details = '{"bank_name": "Commercial Bank of Ethiopia", "account_number": "1000123456789"}'
   WHERE id = '00000000-0000-0000-0000-000000000a10';
  INSERT INTO accounts (id, company_id, code, name, type, subtype) VALUES ('00000000-0000-0000-0000-000000000a53', app_company_id(), '1199', 'New wallet', 'asset', 'mobile_money');
  UPDATE accounts SET currency = 'USD' WHERE id = '00000000-0000-0000-0000-000000000a53';   -- no entries yet: still free
  RAISE NOTICE 'PASS T53 an account with entries keeps its type, kind and currency';
END $$;
-- T54: imported statement lines (027). What the bank said stays; a line is explained by one journal line on the same
-- account for the same amount; excluded lines say why; nothing is deleted. Uses a10 of company A and the T48 expense
-- entry e480, whose line 2 takes 250.00 out of a10.
DO $$ DECLARE jl uuid; other uuid; BEGIN
  SELECT id INTO jl FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e480' AND line_no = 2;
  SELECT id INTO other FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e480' AND line_no = 1;
  INSERT INTO bank_transactions (id, company_id, account_id, posted_on, description, amount, source, external_id, reference) VALUES
    ('00000000-0000-0000-0000-00000000b541', app_company_id(), '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'POS PURCHASE', -250, 'statement', 'FT27181', 'FT27181'),
    ('00000000-0000-0000-0000-00000000b542', app_company_id(), '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'SERVICE CHARGE', -12.5, 'statement', 'FT27182', NULL);
  BEGIN
    INSERT INTO bank_transactions (company_id, account_id, posted_on, description, amount, source) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a40', '2027-06-30', 'x', 1, 'statement');
    RAISE EXCEPTION 'FAIL T54a: a statement line was put on an income account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_transactions (company_id, account_id, posted_on, description, amount, source) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000b11', '2027-06-30', 'x', 1, 'statement');
    RAISE EXCEPTION 'FAIL T54b: a statement line was put on another company''s account';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE bank_transactions SET amount = -251 WHERE id = '00000000-0000-0000-0000-00000000b541';
    RAISE EXCEPTION 'FAIL T54c: what the bank said was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE bank_transactions SET status = 'matched' WHERE id = '00000000-0000-0000-0000-00000000b541';
    RAISE EXCEPTION 'FAIL T54d: a line was matched to nothing';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE bank_transactions SET status = 'matched', journal_line_id = jl WHERE id = '00000000-0000-0000-0000-00000000b542';
    RAISE EXCEPTION 'FAIL T54e: a line was matched to an entry for another amount';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE bank_transactions SET status = 'matched', journal_line_id = other WHERE id = '00000000-0000-0000-0000-00000000b541';
    RAISE EXCEPTION 'FAIL T54f: a line was matched to an entry on another account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE bank_transactions SET status = 'excluded' WHERE id = '00000000-0000-0000-0000-00000000b542';
    RAISE EXCEPTION 'FAIL T54g: a line was excluded without a reason';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE bank_transactions SET status = 'matched', journal_line_id = jl WHERE id = '00000000-0000-0000-0000-00000000b541';
  BEGIN
    UPDATE bank_transactions SET status = 'categorised', journal_line_id = jl WHERE id = '00000000-0000-0000-0000-00000000b541';
    RAISE EXCEPTION 'FAIL T54h: a matched line changed how it is explained without an undo';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_transactions (company_id, account_id, posted_on, description, amount, source, status, journal_line_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'POS PURCHASE again', -250, 'statement', 'matched', jl);
    RAISE EXCEPTION 'FAIL T54i: one entry explained two statement lines';
  EXCEPTION WHEN check_violation OR unique_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_transactions (company_id, account_id, posted_on, description, amount, source, external_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'POS PURCHASE', -250, 'statement', 'FT27181');
    RAISE EXCEPTION 'FAIL T54j: the same bank reference was imported twice';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  UPDATE bank_transactions SET status = 'imported', journal_line_id = NULL WHERE id = '00000000-0000-0000-0000-00000000b541';
  UPDATE bank_transactions SET status = 'excluded', note = 'Duplicate of the card statement' WHERE id = '00000000-0000-0000-0000-00000000b542';
  BEGIN
    DELETE FROM bank_transactions WHERE id = '00000000-0000-0000-0000-00000000b542';
    RAISE EXCEPTION 'FAIL T54k: a statement line was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T54 statement lines keep what the bank said and are explained by one entry on the same account for the same amount';
END $$;
-- T55: reconciliation (028). Starts where the last one ended, finishes only at a zero difference, locks the period,
-- never changes once completed, is undone latest first and only with a reason, and moves its statement lines with it.
-- Uses a new wallet a55 of company A with three entries: 1,000.00 in (1 Jun), 300.00 out (10 Jun), 50.00 in (25 Jun).
DO $$ DECLARE l_in uuid; l_out uuid; l_late uuid; l_sales uuid; n int; st text; BEGIN
  INSERT INTO accounts (id, company_id, code, name, type, subtype) VALUES ('00000000-0000-0000-0000-000000000a55', app_company_id(), '1155', 'Recon wallet', 'asset', 'mobile_money');
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
    ('00000000-0000-0000-0000-00000000e551', app_company_id(), 9551, '2027-06-01', 'ETB', 'test'),
    ('00000000-0000-0000-0000-00000000e552', app_company_id(), 9552, '2027-06-10', 'ETB', 'test'),
    ('00000000-0000-0000-0000-00000000e553', app_company_id(), 9553, '2027-06-25', 'ETB', 'test');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e551', 1, '00000000-0000-0000-0000-000000000a55', 1000, 0, 1000),
    (app_company_id(), '00000000-0000-0000-0000-00000000e551', 2, '00000000-0000-0000-0000-000000000a40', 0, 1000, -1000),
    (app_company_id(), '00000000-0000-0000-0000-00000000e552', 1, '00000000-0000-0000-0000-000000000a40', 300, 0, 300),
    (app_company_id(), '00000000-0000-0000-0000-00000000e552', 2, '00000000-0000-0000-0000-000000000a55', 0, 300, -300),
    (app_company_id(), '00000000-0000-0000-0000-00000000e553', 1, '00000000-0000-0000-0000-000000000a55', 50, 0, 50),
    (app_company_id(), '00000000-0000-0000-0000-00000000e553', 2, '00000000-0000-0000-0000-000000000a40', 0, 50, -50);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  SELECT id INTO l_in FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e551' AND line_no = 1;
  SELECT id INTO l_sales FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e551' AND line_no = 2;
  SELECT id INTO l_out FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e552' AND line_no = 2;
  SELECT id INTO l_late FROM journal_lines WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e553' AND line_no = 1;
  INSERT INTO bank_transactions (id, company_id, account_id, posted_on, description, amount, source) VALUES
    ('00000000-0000-0000-0000-00000000b551', app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-01', 'Deposit', 1000, 'statement');
  UPDATE bank_transactions SET status = 'matched', journal_line_id = l_in WHERE id = '00000000-0000-0000-0000-00000000b551';

  BEGIN
    INSERT INTO reconciliations (company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a40', '2027-06-20', 0, 700);
    RAISE EXCEPTION 'FAIL T55a: an income account was reconciled';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO reconciliations (company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-20', 5, 700);
    RAISE EXCEPTION 'FAIL T55b: the first reconciliation did not start from zero';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO reconciliations (id, company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
    ('00000000-0000-0000-0000-00000000c551', app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-20', 0, 700);
  BEGIN
    INSERT INTO reconciliations (company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-30', 0, 750);
    RAISE EXCEPTION 'FAIL T55c: two reconciliations of one account were in progress';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c551', l_late);
    RAISE EXCEPTION 'FAIL T55d: an entry dated after the statement was ticked';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c551', l_sales);
    RAISE EXCEPTION 'FAIL T55e: an entry on another account was ticked';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c551', l_in);
  BEGIN
    UPDATE reconciliations SET status = 'completed', report = '{}', completed_at = now() WHERE id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55f: a reconciliation finished with a difference';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c551', l_out);
  UPDATE reconciliations SET status = 'completed', report = '{"cleared": 2}', completed_at = now() WHERE id = '00000000-0000-0000-0000-00000000c551';
  SELECT status INTO st FROM bank_transactions WHERE id = '00000000-0000-0000-0000-00000000b551';
  IF st <> 'reconciled' THEN RAISE EXCEPTION 'FAIL T55g: the statement line was left %', st; END IF;

  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e554', app_company_id(), 9554, '2027-06-15', 'ETB', 'test');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e554', 1, '00000000-0000-0000-0000-000000000a55', 5, 0, 5),
      (app_company_id(), '00000000-0000-0000-0000-00000000e554', 2, '00000000-0000-0000-0000-000000000a40', 0, 5, -5);
    RAISE EXCEPTION 'FAIL T55h: an entry was posted into the reconciled period';
  EXCEPTION WHEN check_violation THEN
    IF SQLERRM NOT LIKE '%reconciled through%' THEN RAISE EXCEPTION 'FAIL T55h: refused for another reason: %', SQLERRM; END IF;
  END;
  BEGIN
    UPDATE reconciliations SET ending_balance = 701 WHERE id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55i: a completed reconciliation changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM reconciliation_lines WHERE reconciliation_id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55j: a completed reconciliation lost its entries';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM reconciliations WHERE id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55k: a completed reconciliation was deleted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO reconciliations (company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-30', 0, 750);
    RAISE EXCEPTION 'FAIL T55l: the next reconciliation did not start from the last ending balance';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO reconciliations (company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-20', 700, 700);
    RAISE EXCEPTION 'FAIL T55m: a reconciliation ended on the date already reconciled';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE reconciliations SET status = 'undone', undone_at = now() WHERE id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55n: a reconciliation was undone without a reason';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- The next month, then undo: the later one first.
  INSERT INTO reconciliations (id, company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
    ('00000000-0000-0000-0000-00000000c552', app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-30', 700, 750);
  INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c552', l_late);
  UPDATE reconciliations SET status = 'completed', report = '{"cleared": 1}', completed_at = now() WHERE id = '00000000-0000-0000-0000-00000000c552';
  BEGIN
    UPDATE reconciliations SET status = 'undone', undone_at = now(), undo_reason = 'Wrong statement' WHERE id = '00000000-0000-0000-0000-00000000c551';
    RAISE EXCEPTION 'FAIL T55o: an earlier reconciliation was undone before a later one';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE reconciliations SET status = 'undone', undone_at = now(), undo_reason = 'Bank sent a corrected statement' WHERE id = '00000000-0000-0000-0000-00000000c552';
  UPDATE reconciliations SET status = 'undone', undone_at = now(), undo_reason = 'Wrong statement' WHERE id = '00000000-0000-0000-0000-00000000c551';
  SELECT count(*) INTO n FROM reconciliation_lines WHERE reconciliation_id IN ('00000000-0000-0000-0000-00000000c551', '00000000-0000-0000-0000-00000000c552');
  SELECT status INTO st FROM bank_transactions WHERE id = '00000000-0000-0000-0000-00000000b551';
  IF n <> 0 OR st <> 'matched' THEN RAISE EXCEPTION 'FAIL T55p: undoing left % cleared entries and the statement line %', n, st; END IF;

  -- A draft is discarded with its ticks; the period is open again.
  INSERT INTO reconciliations (id, company_id, account_id, statement_date, opening_balance, ending_balance) VALUES
    ('00000000-0000-0000-0000-00000000c553', app_company_id(), '00000000-0000-0000-0000-000000000a55', '2027-06-20', 0, 700);
  INSERT INTO reconciliation_lines (company_id, reconciliation_id, journal_line_id) VALUES (app_company_id(), '00000000-0000-0000-0000-00000000c553', l_in);
  DELETE FROM reconciliations WHERE id = '00000000-0000-0000-0000-00000000c553';
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e555', app_company_id(), 9555, '2027-06-15', 'ETB', 'test');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e555', 1, '00000000-0000-0000-0000-000000000a55', 5, 0, 5),
    (app_company_id(), '00000000-0000-0000-0000-00000000e555', 2, '00000000-0000-0000-0000-000000000a40', 0, 5, -5);
  SET CONSTRAINTS ALL IMMEDIATE;
  RAISE NOTICE 'PASS T55 reconciliations finish at zero difference, lock the period, never change and are undone latest first with a reason';
END $$;
-- T56: cheques (029). Only cheques carry a number; a cheque written has one, used once per bank account; only a cheque
-- received bounces, and not before it was received. Uses bank a10, customer c1 and supplier c8 of company A.
DO $$ BEGIN
  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount) VALUES
      (app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cheque', 'ETB', 10);
    RAISE EXCEPTION 'FAIL T56a: a cheque was written without a number';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount, cheque_number) VALUES
    ('00000000-0000-0000-0000-0000000005e1', app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cheque', 'ETB', 10, '000101');
  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount, cheque_number) VALUES
      (app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cheque', 'ETB', 20, '000101');
    RAISE EXCEPTION 'FAIL T56b: one cheque number was used twice on one account';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    INSERT INTO payments (company_id, direction, contact_id, account_id, payment_date, method, currency, amount, cheque_number) VALUES
      (app_company_id(), 'out', '00000000-0000-0000-0000-0000000000c8', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'bank_transfer', 'ETB', 20, '000102');
    RAISE EXCEPTION 'FAIL T56c: a bank transfer carried a cheque number';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE payments SET status = 'reversed', bounced_on = '2027-06-30' WHERE id = '00000000-0000-0000-0000-0000000005e1';
    RAISE EXCEPTION 'FAIL T56d: a cheque the company wrote bounced';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount, cheque_number) VALUES
    ('00000000-0000-0000-0000-0000000005e2', app_company_id(), 'in', '00000000-0000-0000-0000-0000000000c1', '00000000-0000-0000-0000-000000000a10', '2027-06-30', 'cheque', 'ETB', 30, 'CQ-55');
  BEGIN
    UPDATE payments SET bounced_on = '2027-06-30' WHERE id = '00000000-0000-0000-0000-0000000005e2';
    RAISE EXCEPTION 'FAIL T56e: a cheque bounced and its payment still stands';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE payments SET status = 'reversed', bounced_on = '2027-06-29' WHERE id = '00000000-0000-0000-0000-0000000005e2';
    RAISE EXCEPTION 'FAIL T56f: a cheque bounced before it was received';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE payments SET status = 'reversed', bounced_on = '2027-06-30' WHERE id = '00000000-0000-0000-0000-0000000005e2';
  BEGIN
    UPDATE payments SET bounced_on = '2027-07-01' WHERE id = '00000000-0000-0000-0000-0000000005e2';
    RAISE EXCEPTION 'FAIL T56g: the day a cheque bounced was changed afterwards';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T56 cheques carry their numbers once per account and only cheques received bounce';
END $$;
-- T57: petty cash (030). Only cash accounts keep a float; a count says who counted, leaves the books holding what was
-- counted (a difference posted first), and never changes. Uses a new petty cash account a57 of company A: 500.00 put in
-- on 1 Jun 2027, and a 20.00 shortage posted on 3 Jun.
DO $$ BEGIN
  INSERT INTO accounts (id, company_id, code, name, type, subtype) VALUES ('00000000-0000-0000-0000-000000000a57', app_company_id(), '1057', 'Petty cash T57', 'asset', 'cash');
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
    ('00000000-0000-0000-0000-00000000e571', app_company_id(), 9571, '2027-06-01', 'ETB', 'test'),
    ('00000000-0000-0000-0000-00000000e572', app_company_id(), 9572, '2027-06-03', 'ETB', 'test');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e571', 1, '00000000-0000-0000-0000-000000000a57', 500, 0, 500),
    (app_company_id(), '00000000-0000-0000-0000-00000000e571', 2, '00000000-0000-0000-0000-000000000a40', 0, 500, -500),
    (app_company_id(), '00000000-0000-0000-0000-00000000e572', 1, '00000000-0000-0000-0000-000000000a40', 20, 0, 20),
    (app_company_id(), '00000000-0000-0000-0000-00000000e572', 2, '00000000-0000-0000-0000-000000000a57', 0, 20, -20);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;

  BEGIN
    UPDATE accounts SET float_amount = 1000 WHERE id = '00000000-0000-0000-0000-000000000a10';
    RAISE EXCEPTION 'FAIL T57a: a bank account kept a float';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET float_amount = 0 WHERE id = '00000000-0000-0000-0000-000000000a57';
    RAISE EXCEPTION 'FAIL T57b: a float of nothing was kept';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE accounts SET float_amount = 500 WHERE id = '00000000-0000-0000-0000-000000000a57';

  -- 2 Jun: 500.00 counted, as the books say.
  INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference) VALUES
    (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-02', 'Meron Alemu', 500, 500, 0);
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-02', 'Meron Alemu', 480, 500, -20);
    RAISE EXCEPTION 'FAIL T57c: a shortage was counted and nothing posted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-02', 'Meron Alemu', 490, 500, 0);
    RAISE EXCEPTION 'FAIL T57d: a count disagreed with its own difference';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-02', ' ', 500, 500, 0);
    RAISE EXCEPTION 'FAIL T57e: a count did not say who counted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a10', '2027-06-02', 'Meron Alemu', 1, 1, 0);
    RAISE EXCEPTION 'FAIL T57f: cash was counted in a bank account';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- 3 Jun: 480.00 counted; the 20.00 shortage posted that day; the books hold 480.00.
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference, journal_entry_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-03', 'Meron Alemu', 470, 490, -20, '00000000-0000-0000-0000-00000000e572');
    RAISE EXCEPTION 'FAIL T57g: after a count the books did not hold what was counted';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO cash_counts (company_id, account_id, counted_on, counted_by_name, counted, book_balance, difference, journal_entry_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-03', 'Meron Alemu', 480, 500, -20, '00000000-0000-0000-0000-00000000e571');
    RAISE EXCEPTION 'FAIL T57h: a count named an entry that is not its difference';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO cash_counts (id, company_id, account_id, counted_on, counted_by_name, denominations, counted, book_balance, difference, journal_entry_id) VALUES
    ('00000000-0000-0000-0000-0000000005c7', app_company_id(), '00000000-0000-0000-0000-000000000a57', '2027-06-03', 'Meron Alemu', '{"200": 2, "50": 1, "10": 3}',
     480, 500, -20, '00000000-0000-0000-0000-00000000e572');
  BEGIN
    UPDATE cash_counts SET counted_by_name = 'Someone else' WHERE id = '00000000-0000-0000-0000-0000000005c7';
    RAISE EXCEPTION 'FAIL T57i: a cash count was changed';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM cash_counts WHERE id = '00000000-0000-0000-0000-0000000005c7';
    RAISE EXCEPTION 'FAIL T57j: a cash count was removed';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T57 floats are for cash, and a cash count says who counted and leaves the books holding what was counted';
END $$;
-- T58: the chart of accounts (031). System accounts keep their key and stay active; an account is archived only when it
-- holds nothing and nothing active sits under it; sub-accounts share their parent's type, never loop, and go at most four
-- levels deep; codes are plain; accounts are never deleted. Uses a11 (receivable, a system account), a10 (the bank, with
-- entries) and new income accounts a58..a5c of company A.
DO $$ BEGIN
  BEGIN
    UPDATE accounts SET system_key = 'something_else' WHERE id = '00000000-0000-0000-0000-000000000a11';
    RAISE EXCEPTION 'FAIL T58a: a system account lost its key';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000a11';
    RAISE EXCEPTION 'FAIL T58b: a system account was archived';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000a10';
    RAISE EXCEPTION 'FAIL T58c: an account holding money was archived';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO accounts (id, company_id, code, name, type) VALUES
    ('00000000-0000-0000-0000-000000000a58', app_company_id(), '4580', 'Other sales', 'income');
  INSERT INTO accounts (id, company_id, code, name, type, parent_id) VALUES
    ('00000000-0000-0000-0000-000000000a59', app_company_id(), '4581', 'Sales of samples', 'income', '00000000-0000-0000-0000-000000000a58'),
    ('00000000-0000-0000-0000-000000000a5a', app_company_id(), '4582', 'Sample packs', 'income', '00000000-0000-0000-0000-000000000a59'),
    ('00000000-0000-0000-0000-000000000a5b', app_company_id(), '4583', 'Sample packs, 250 g', 'income', '00000000-0000-0000-0000-000000000a5a');
  BEGIN
    INSERT INTO accounts (company_id, code, name, type, parent_id) VALUES (app_company_id(), '4584', 'Too deep', 'income', '00000000-0000-0000-0000-000000000a5b');
    RAISE EXCEPTION 'FAIL T58d: an account went five levels deep';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO accounts (company_id, code, name, type, parent_id) VALUES (app_company_id(), '1590', 'Asset under income', 'asset', '00000000-0000-0000-0000-000000000a58');
    RAISE EXCEPTION 'FAIL T58e: a sub-account sat under an account of another type';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO accounts (company_id, code, name, type, parent_id) VALUES (app_company_id(), '4590', 'Under B', 'income', '00000000-0000-0000-0000-000000000b11');
    RAISE EXCEPTION 'FAIL T58f: a sub-account sat under another company''s account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET parent_id = '00000000-0000-0000-0000-000000000a5a' WHERE id = '00000000-0000-0000-0000-000000000a58';
    RAISE EXCEPTION 'FAIL T58g: an account sat under its own sub-account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000a58';
    RAISE EXCEPTION 'FAIL T58h: an account was archived with active sub-accounts';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE accounts SET type = 'expense' WHERE id = '00000000-0000-0000-0000-000000000a58';
    RAISE EXCEPTION 'FAIL T58i: an account changed type away from its sub-accounts';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO accounts (company_id, code, name, type) VALUES (app_company_id(), 'bad code!', 'Bad', 'income');
    RAISE EXCEPTION 'FAIL T58j: an account got a code with spaces and marks';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM accounts WHERE id = '00000000-0000-0000-0000-000000000a5b';
    RAISE EXCEPTION 'FAIL T58k: an account was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  -- Archived from the bottom up, when empty: allowed.
  UPDATE accounts SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000a5b';
  UPDATE accounts SET is_active = false WHERE id = '00000000-0000-0000-0000-000000000a5a';
  RAISE NOTICE 'PASS T58 the chart keeps system accounts, archives only empty accounts and keeps sub-accounts in one type, no deeper than four levels';
END $$;
-- T59: manual journals (032) never touch accounts kept by their documents; other entries (an invoice) still post to
-- them. Uses receivable a11, sales a40 and bank a10 of company A.
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES ('00000000-0000-0000-0000-00000000e591', app_company_id(), 9591, '2027-06-30', 'ETB', 'manual');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e591', 1, '00000000-0000-0000-0000-000000000a11', 100, 0, 100),
      (app_company_id(), '00000000-0000-0000-0000-00000000e591', 2, '00000000-0000-0000-0000-000000000a40', 0, 100, -100);
    RAISE EXCEPTION 'FAIL T59a: a manual journal posted to the receivables';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
    ('00000000-0000-0000-0000-00000000e592', app_company_id(), 9592, '2027-06-30', 'ETB', 'invoice'),
    ('00000000-0000-0000-0000-00000000e593', app_company_id(), 9593, '2027-06-30', 'ETB', 'manual');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e592', 1, '00000000-0000-0000-0000-000000000a11', 100, 0, 100),
    (app_company_id(), '00000000-0000-0000-0000-00000000e592', 2, '00000000-0000-0000-0000-000000000a40', 0, 100, -100),
    (app_company_id(), '00000000-0000-0000-0000-00000000e593', 1, '00000000-0000-0000-0000-000000000a10', 100, 0, 100),
    (app_company_id(), '00000000-0000-0000-0000-00000000e593', 2, '00000000-0000-0000-0000-000000000a40', 0, 100, -100);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  RAISE NOTICE 'PASS T59 manual journals leave the accounts kept by documents alone';
END $$;
-- T60: journals waiting for approval (033). Asked by the person signed in, decided once by someone else (or withdrawn
-- by the asker), an approval names the manual journal it posted, and what was asked never changes. Hana (f1) asks;
-- Other (f2) decides.
DO $$ DECLARE lines jsonb := '[{"account_id": "x", "debit": "900"}, {"account_id": "y", "credit": "900"}]'; BEGIN
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false);
  BEGIN
    INSERT INTO journal_requests (company_id, entry_date, memo, lines, total, requested_by) VALUES
      (app_company_id(), '2027-06-30', 'Asked for someone else', lines, 900, '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T60a: a request was made in someone else''s name';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO journal_requests (company_id, entry_date, memo, lines, total, requested_by, status) VALUES
      (app_company_id(), '2027-06-30', 'Already approved', lines, 900, '00000000-0000-0000-0000-0000000000f1', 'approved');
    RAISE EXCEPTION 'FAIL T60b: a request started approved';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_requests (id, company_id, entry_date, memo, lines, total, requested_by) VALUES
    ('00000000-0000-0000-0000-0000000006a1', app_company_id(), '2027-06-30', 'Year-end accrual', lines, 900, '00000000-0000-0000-0000-0000000000f1'),
    ('00000000-0000-0000-0000-0000000006a2', app_company_id(), '2027-06-30', 'Changed my mind', lines, 900, '00000000-0000-0000-0000-0000000000f1'),
    ('00000000-0000-0000-0000-0000000006a3', app_company_id(), '2027-06-30', 'Bonus provision', lines, 900, '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    UPDATE journal_requests SET status = 'rejected', decided_by = app_user_id(), decided_at = now(), decision_note = 'No'
     WHERE id = '00000000-0000-0000-0000-0000000006a1';
    RAISE EXCEPTION 'FAIL T60c: the person who asked decided their own request';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE journal_requests SET status = 'withdrawn', decided_by = app_user_id(), decided_at = now() WHERE id = '00000000-0000-0000-0000-0000000006a2';

  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', false);
  BEGIN
    UPDATE journal_requests SET status = 'withdrawn', decided_by = app_user_id(), decided_at = now() WHERE id = '00000000-0000-0000-0000-0000000006a1';
    RAISE EXCEPTION 'FAIL T60d: someone other than the asker withdrew a request';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE journal_requests SET status = 'rejected', decided_by = app_user_id(), decided_at = now() WHERE id = '00000000-0000-0000-0000-0000000006a1';
    RAISE EXCEPTION 'FAIL T60e: a request was rejected without saying why';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE journal_requests SET status = 'rejected', decided_by = app_user_id(), decided_at = now(), decision_note = 'Accrue it in July instead'
   WHERE id = '00000000-0000-0000-0000-0000000006a1';
  BEGIN
    UPDATE journal_requests SET status = 'approved' WHERE id = '00000000-0000-0000-0000-0000000006a1';
    RAISE EXCEPTION 'FAIL T60f: a decided request was decided again';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE journal_requests SET status = 'approved', decided_by = app_user_id(), decided_at = now(), journal_entry_id = '00000000-0000-0000-0000-00000000e480'
     WHERE id = '00000000-0000-0000-0000-0000000006a3';
    RAISE EXCEPTION 'FAIL T60g: an approval named an entry it did not post';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e601', app_company_id(), 9601, '2027-06-30', 'ETB', 'manual', '00000000-0000-0000-0000-0000000006a3');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e601', 1, '00000000-0000-0000-0000-000000000a40', 900, 0, 900),
    (app_company_id(), '00000000-0000-0000-0000-00000000e601', 2, '00000000-0000-0000-0000-000000000a10', 0, 900, -900);
  UPDATE journal_requests SET status = 'approved', decided_by = app_user_id(), decided_at = now(), journal_entry_id = '00000000-0000-0000-0000-00000000e601'
   WHERE id = '00000000-0000-0000-0000-0000000006a3';
  BEGIN
    UPDATE journal_requests SET memo = 'Something else' WHERE id = '00000000-0000-0000-0000-0000000006a3';
    RAISE EXCEPTION 'FAIL T60h: an approved request changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM journal_requests WHERE id = '00000000-0000-0000-0000-0000000006a1';
    RAISE EXCEPTION 'FAIL T60i: a request was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false);
  RAISE NOTICE 'PASS T60 a journal above the limit is asked by one person, decided once by another, and never changes';
END $$;
-- T61: revaluations (034). One per date, made by the person signed in, naming its own revaluation entry of that date
-- and the reversal of it dated after; never changed or deleted. Uses sales a40 and bank a10 of company A (as user f1).
DO $$ BEGIN
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false);
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e611', app_company_id(), 9611, '2027-06-30', 'ETB', 'revaluation', '00000000-0000-0000-0000-0000000006b1'),
    ('00000000-0000-0000-0000-00000000e612', app_company_id(), 9612, '2027-07-01', 'ETB', 'reversal', '00000000-0000-0000-0000-00000000e611'),
    ('00000000-0000-0000-0000-00000000e613', app_company_id(), 9613, '2027-06-30', 'ETB', 'manual', NULL);
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e611', 1, '00000000-0000-0000-0000-000000000a10', 40, 0, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e611', 2, '00000000-0000-0000-0000-000000000a40', 0, 40, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e612', 1, '00000000-0000-0000-0000-000000000a40', 40, 0, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e612', 2, '00000000-0000-0000-0000-000000000a10', 0, 40, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e613', 1, '00000000-0000-0000-0000-000000000a10', 1, 0, 1),
    (app_company_id(), '00000000-0000-0000-0000-00000000e613', 2, '00000000-0000-0000-0000-000000000a40', 0, 1, -1);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  BEGIN
    INSERT INTO fx_revaluations (id, company_id, as_of, rates, lines, net, journal_entry_id, reversal_entry_id, created_by) VALUES
      ('00000000-0000-0000-0000-0000000006b1', app_company_id(), '2027-06-30', '{"USD": "155"}', '[]', 40,
       '00000000-0000-0000-0000-00000000e613', '00000000-0000-0000-0000-00000000e612', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T61a: a revaluation named a manual journal as its entry';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO fx_revaluations (id, company_id, as_of, rates, lines, net, journal_entry_id, reversal_entry_id, created_by) VALUES
      ('00000000-0000-0000-0000-0000000006b1', app_company_id(), '2027-06-30', '{"USD": "155"}', '[]', 40,
       '00000000-0000-0000-0000-00000000e611', '00000000-0000-0000-0000-00000000e613', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T61b: a revaluation named something other than its reversal';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO fx_revaluations (id, company_id, as_of, rates, lines, net, journal_entry_id, reversal_entry_id, created_by) VALUES
      ('00000000-0000-0000-0000-0000000006b1', app_company_id(), '2027-06-30', '{"USD": "155"}', '[]', 40,
       '00000000-0000-0000-0000-00000000e611', '00000000-0000-0000-0000-00000000e612', '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T61c: a revaluation was made in someone else''s name';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO fx_revaluations (id, company_id, as_of, rates, lines, net, journal_entry_id, reversal_entry_id, created_by) VALUES
    ('00000000-0000-0000-0000-0000000006b1', app_company_id(), '2027-06-30', '{"USD": "155"}', '[]', 40,
     '00000000-0000-0000-0000-00000000e611', '00000000-0000-0000-0000-00000000e612', '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    INSERT INTO fx_revaluations (company_id, as_of, rates, lines, net, journal_entry_id, reversal_entry_id, created_by) VALUES
      (app_company_id(), '2027-06-30', '{}', '[]', 0, '00000000-0000-0000-0000-00000000e611', '00000000-0000-0000-0000-00000000e612', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T61d: a date was revalued twice';
  EXCEPTION WHEN unique_violation OR check_violation THEN NULL; END;
  BEGIN
    UPDATE fx_revaluations SET net = 41 WHERE id = '00000000-0000-0000-0000-0000000006b1';
    RAISE EXCEPTION 'FAIL T61e: a revaluation was changed';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM fx_revaluations WHERE id = '00000000-0000-0000-0000-0000000006b1';
    RAISE EXCEPTION 'FAIL T61f: a revaluation was deleted';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T61 a revaluation is made once a date, names its entry and its reversal, and never changes';
END $$;
-- T62: the year-end close (035). A closing entry belongs to a fiscal year and is dated its last day, one standing per
-- year; a year is closed only with it and with the books locked through its end. Uses a fiscal year 2027-07-01 to
-- 2028-06-30 of company A; the lock date is put back afterwards.
DO $$ DECLARE was date; BEGIN
  SELECT books_closed_until INTO was FROM companies WHERE id = app_company_id();
  INSERT INTO fiscal_years (id, company_id, starts_on, ends_on) VALUES
    ('00000000-0000-0000-0000-0000000006f1', app_company_id(), '2027-07-01', '2028-06-30');
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e621', app_company_id(), 9621, '2028-06-29', 'ETB', 'closing', '00000000-0000-0000-0000-0000000006f1');
    RAISE EXCEPTION 'FAIL T62a: a closing entry was dated before the year''s last day';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE fiscal_years SET status = 'closing' WHERE id = '00000000-0000-0000-0000-0000000006f1';
  BEGIN
    UPDATE fiscal_years SET status = 'closed' WHERE id = '00000000-0000-0000-0000-0000000006f1';
    RAISE EXCEPTION 'FAIL T62b: a year closed without its closing entry';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e622', app_company_id(), 9622, '2028-06-30', 'ETB', 'closing', '00000000-0000-0000-0000-0000000006f1');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e622', 1, '00000000-0000-0000-0000-000000000a40', 100, 0, 100),
    (app_company_id(), '00000000-0000-0000-0000-00000000e622', 2, '00000000-0000-0000-0000-000000000a10', 0, 100, -100);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e623', app_company_id(), 9623, '2028-06-30', 'ETB', 'closing', '00000000-0000-0000-0000-0000000006f1');
    RAISE EXCEPTION 'FAIL T62c: a year got two standing closing entries';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    UPDATE fiscal_years SET status = 'closed' WHERE id = '00000000-0000-0000-0000-0000000006f1';
    RAISE EXCEPTION 'FAIL T62d: a year closed with its books still open';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE companies SET books_closed_until = '2028-06-30' WHERE id = app_company_id();
  UPDATE fiscal_years SET status = 'closed' WHERE id = '00000000-0000-0000-0000-0000000006f1';
  UPDATE companies SET books_closed_until = was WHERE id = app_company_id();
  RAISE NOTICE 'PASS T62 a year closes with one closing entry dated its last day and its books locked';
END $$;
-- T63: recurring and auto-reversing journals (036). Templates repeat monthly, quarterly or yearly within their end; a
-- journal waiting for approval reverses after its own date and never changes when. Company A, as user f1.
DO $$ DECLARE lines jsonb := '[{"account_id": "x", "debit": "900"}, {"account_id": "y", "credit": "900"}]'; BEGIN
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false);
  BEGIN
    INSERT INTO recurring_journals (company_id, name, memo, lines, total, frequency, next_on, posts_as) VALUES
      (app_company_id(), 'Weekly cleaning', 'Cleaning', lines, 900, 'weekly', '2027-07-01', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T63a: a template repeated weekly';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO recurring_journals (company_id, name, memo, lines, total, frequency, next_on, ends_on, posts_as) VALUES
      (app_company_id(), 'Rent', 'Rent', lines, 900, 'monthly', '2027-07-01', '2027-06-30', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T63b: an active template''s next date was after its end';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO recurring_journals (company_id, name, memo, lines, total, frequency, next_on, posts_as) VALUES
      (app_company_id(), 'Rent', 'Rent', lines, 900, 'monthly', '2027-07-01', '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T63g: a template was set up to post as someone else';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO recurring_journals (id, company_id, name, memo, lines, total, frequency, next_on, ends_on, posts_as) VALUES
    ('00000000-0000-0000-0000-0000000006c1', app_company_id(), 'Office rent', 'Office rent for the month', lines, 900, 'monthly', '2027-07-01', '2027-12-31', '00000000-0000-0000-0000-0000000000f1');
  -- The last one is posted: the template ends.
  UPDATE recurring_journals SET next_on = '2028-01-01', is_active = false WHERE id = '00000000-0000-0000-0000-0000000006c1';
  -- The scheduler sees which templates are due, but only outside any company's context.
  INSERT INTO recurring_journals (id, company_id, name, memo, lines, total, frequency, next_on, posts_as) VALUES
    ('00000000-0000-0000-0000-0000000006c3', app_company_id(), 'Depreciation', 'Monthly depreciation', lines, 900, 'monthly', '2027-08-31', '00000000-0000-0000-0000-0000000000f1');
  IF EXISTS (SELECT 1 FROM recurring_journals_due('2027-09-01')) THEN
    RAISE EXCEPTION 'FAIL T63e: due templates were listed inside a company''s context';
  END IF;
  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  IF (SELECT array_agg(template_id) FROM recurring_journals_due('2027-09-01')) IS DISTINCT FROM ARRAY['00000000-0000-0000-0000-0000000006c3'::uuid] THEN
    RAISE EXCEPTION 'FAIL T63f: the scheduler did not see exactly the active template that is due';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);

  BEGIN
    INSERT INTO journal_requests (company_id, entry_date, memo, lines, total, requested_by, reverse_on) VALUES
      (app_company_id(), '2027-06-30', 'Accrual reversing too early', lines, 900, '00000000-0000-0000-0000-0000000000f1', '2027-06-30');
    RAISE EXCEPTION 'FAIL T63c: a journal reversed on its own date';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_requests (id, company_id, entry_date, memo, lines, total, requested_by, reverse_on) VALUES
    ('00000000-0000-0000-0000-0000000006c2', app_company_id(), '2027-06-30', 'June accrual', lines, 900, '00000000-0000-0000-0000-0000000000f1', '2027-07-01');
  BEGIN
    UPDATE journal_requests SET reverse_on = '2027-07-31' WHERE id = '00000000-0000-0000-0000-0000000006c2';
    RAISE EXCEPTION 'FAIL T63d: when a journal reverses was changed after it was asked';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T63 recurring journals repeat within their end and a journal reverses after its date, as asked';
END $$;
-- T64: the adjustments period for the auditor (037). An audit adjustment goes into a closed year that is not signed off,
-- dated its last day, never on accounts kept by documents; signing off needs a closed year and then nothing about the
-- year changes. Uses T62's closed year 2027-07-01 to 2028-06-30 of company A and an open year after it.
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e641', app_company_id(), 9641, '2028-06-29', 'ETB', 'adjustment', '00000000-0000-0000-0000-0000000006f1');
    RAISE EXCEPTION 'FAIL T64a: an audit adjustment was dated before the year''s last day';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO fiscal_years (id, company_id, starts_on, ends_on) VALUES
    ('00000000-0000-0000-0000-0000000006f2', app_company_id(), '2028-07-01', '2029-06-30');
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e642', app_company_id(), 9642, '2029-06-30', 'ETB', 'adjustment', '00000000-0000-0000-0000-0000000006f2');
    RAISE EXCEPTION 'FAIL T64b: an audit adjustment went into a year still open';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fiscal_years SET adjustments_closed_at = now(), adjustments_closed_by = '00000000-0000-0000-0000-0000000000f1' WHERE id = '00000000-0000-0000-0000-0000000006f2';
    RAISE EXCEPTION 'FAIL T64c: an open year''s audit was signed off';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e643', app_company_id(), 9643, '2028-06-30', 'ETB', 'adjustment', '00000000-0000-0000-0000-0000000006f1');
  BEGIN
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e643', 1, '00000000-0000-0000-0000-000000000a11', 50, 0, 50);
    RAISE EXCEPTION 'FAIL T64d: an audit adjustment touched receivables';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e643', 1, '00000000-0000-0000-0000-000000000a40', 50, 0, 50),
    (app_company_id(), '00000000-0000-0000-0000-00000000e643', 2, '00000000-0000-0000-0000-000000000a10', 0, 50, -50);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;

  -- Signed off: no more adjustments, and the year stays closed as it is.
  BEGIN
    UPDATE fiscal_years SET adjustments_closed_at = now() WHERE id = '00000000-0000-0000-0000-0000000006f1';
    RAISE EXCEPTION 'FAIL T64e: an audit was signed off without saying by whom';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE fiscal_years SET adjustments_closed_at = now(), adjustments_closed_by = '00000000-0000-0000-0000-0000000000f1' WHERE id = '00000000-0000-0000-0000-0000000006f1';
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e644', app_company_id(), 9644, '2028-06-30', 'ETB', 'adjustment', '00000000-0000-0000-0000-0000000006f1');
    RAISE EXCEPTION 'FAIL T64f: an audit adjustment went into a year signed off';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fiscal_years SET status = 'reopened' WHERE id = '00000000-0000-0000-0000-0000000006f1';
    RAISE EXCEPTION 'FAIL T64g: a year signed off was reopened';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fiscal_years SET adjustments_closed_at = NULL, adjustments_closed_by = NULL WHERE id = '00000000-0000-0000-0000-0000000006f1';
    RAISE EXCEPTION 'FAIL T64h: a sign-off was taken back';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T64 audit adjustments go into a closed year until it is signed off, then the year stays as it is';
END $$;
-- T65: reviews of unusual entries (038). An entry is reviewed once, by the person signed in, in its own company, and
-- the review is never changed or removed. Company A, as user f1, on T1's entry e001.
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entry_reviews (company_id, journal_entry_id, reviewed_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e001', '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T65a: an entry was marked reviewed by someone else';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO journal_entry_reviews (company_id, journal_entry_id, reviewed_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000eeee1', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T65b: a review named an entry that is not this company''s';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  BEGIN
    INSERT INTO journal_entry_reviews (company_id, journal_entry_id, reviewed_by) VALUES
      ('00000000-0000-0000-0000-00000000000b', '00000000-0000-0000-0000-00000000e001', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T65c: a review was written into another company';
  EXCEPTION WHEN insufficient_privilege OR foreign_key_violation THEN NULL; END;
  INSERT INTO journal_entry_reviews (company_id, journal_entry_id, reviewed_by, flags, note) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e001', '00000000-0000-0000-0000-0000000000f1', '{round_amount}', 'Agreed to the contract');
  BEGIN
    INSERT INTO journal_entry_reviews (company_id, journal_entry_id, reviewed_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e001', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T65d: an entry was reviewed twice';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    UPDATE journal_entry_reviews SET note = 'Changed my mind' WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e001';
    RAISE EXCEPTION 'FAIL T65e: a review was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM journal_entry_reviews WHERE journal_entry_id = '00000000-0000-0000-0000-00000000e001';
    RAISE EXCEPTION 'FAIL T65f: a review was removed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T65 an entry is reviewed once, by the person signed in, and the review is kept as it was';
END $$;
-- T66: tax returns (039). Filed by the person signed in, for an ended period that no other return of the kind covers,
-- figures that add up and never change; the filed period is locked for that tax's accounts; a return is settled once,
-- by its own settlement entry dated after the period. Company A, as user f1 (a22 is VAT payable, a10 the bank).
DO $$ DECLARE figures jsonb := '{"boxes": []}'; BEGIN
  BEGIN
    INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
      (app_company_id(), 'vat', '2027-09-01', '2027-09-30', '2027-10-15', 1500, 600, 900, figures, '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T66a: a return was filed by someone else';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
      (app_company_id(), 'vat', '2027-09-01', '2027-09-30', '2027-10-15', 1500, 600, 800, figures, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T66b: a return''s figures did not add up';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
      (app_company_id(), 'vat', '2027-09-01', '2027-09-30', '2027-09-30', 1500, 600, 900, figures, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T66c: a return was filed before its period ended';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO tax_returns (id, company_id, kind, period_start, period_end, filed_on, reference, output_tax, input_tax, net, figures, filed_by) VALUES
    ('00000000-0000-0000-0000-0000000006d1', app_company_id(), 'vat', '2027-09-01', '2027-09-30', '2027-10-15', 'MOR-77120', 1500, 600, 900, figures, '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
      (app_company_id(), 'vat', '2027-09-15', '2027-10-15', '2027-10-20', 0, 0, 0, figures, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T66d: two VAT returns covered the same days';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE tax_returns SET output_tax = 1, net = -599 WHERE id = '00000000-0000-0000-0000-0000000006d1';
    RAISE EXCEPTION 'FAIL T66e: a filed return was changed';
  EXCEPTION WHEN insufficient_privilege OR check_violation THEN NULL; END;

  -- The filed period is locked for VAT: an entry on VAT payable dated inside it is refused, after it is fine.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
    ('00000000-0000-0000-0000-00000000e661', app_company_id(), 9661, '2027-09-15', 'ETB', 'test');
  BEGIN
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e661', 1, '00000000-0000-0000-0000-000000000a22', 0, 50, -50);
    RAISE EXCEPTION 'FAIL T66f: an entry posted VAT into a filed period';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e661', 1, '00000000-0000-0000-0000-000000000a10', 50, 0, 50),
    (app_company_id(), '00000000-0000-0000-0000-00000000e661', 2, '00000000-0000-0000-0000-000000000a40', 0, 50, -50);   -- other accounts are open

  -- Settled once, by its own entry dated after the period.
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e662', app_company_id(), 9662, '2027-09-30', 'ETB', 'tax_settlement', '00000000-0000-0000-0000-0000000006d1');
    RAISE EXCEPTION 'FAIL T66g: a settlement was dated inside its period';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e663', app_company_id(), 9663, '2027-10-25', 'ETB', 'tax_settlement', '00000000-0000-0000-0000-0000000006d1');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e663', 1, '00000000-0000-0000-0000-000000000a22', 900, 0, 900),
    (app_company_id(), '00000000-0000-0000-0000-00000000e663', 2, '00000000-0000-0000-0000-000000000a10', 0, 900, -900);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  BEGIN
    UPDATE tax_returns SET settlement_entry_id = '00000000-0000-0000-0000-00000000e661', settled_on = '2027-09-15' WHERE id = '00000000-0000-0000-0000-0000000006d1';
    RAISE EXCEPTION 'FAIL T66h: a return was settled by an entry that is not its settlement';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE tax_returns SET settlement_entry_id = '00000000-0000-0000-0000-00000000e663', settled_on = '2027-10-25' WHERE id = '00000000-0000-0000-0000-0000000006d1';
  BEGIN
    UPDATE tax_returns SET settlement_entry_id = NULL, settled_on = NULL WHERE id = '00000000-0000-0000-0000-0000000006d1';
    RAISE EXCEPTION 'FAIL T66i: a settlement was taken off its return';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T66 a tax return is filed once for its period, locks that tax there, and is settled once after it';
END $$;
-- T67: projects and job costing (040). A project's details belong to a project of the company, for one of its
-- customers; a document line names a project of its own company. Company A, as user f1 (d43 is company B's location).
DO $$ BEGIN
  INSERT INTO dimensions (id, company_id, kind, code, name) VALUES
    ('00000000-0000-0000-0000-000000000d71', app_company_id(), 'project', 'P-T67', 'Bole road resurfacing'),
    ('00000000-0000-0000-0000-000000000d72', app_company_id(), 'class', 'CL-T67', 'Retail');
  -- c7 is a supplier of company A (added with the bills above), not a customer.
  BEGIN
    INSERT INTO projects (dimension_id, company_id) VALUES ('00000000-0000-0000-0000-000000000d72', app_company_id());
    RAISE EXCEPTION 'FAIL T67a: a class was given project details';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO projects (dimension_id, company_id, customer_id) VALUES
      ('00000000-0000-0000-0000-000000000d71', app_company_id(), '00000000-0000-0000-0000-0000000000c7');
    RAISE EXCEPTION 'FAIL T67b: a project was for a supplier, not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO projects (dimension_id, company_id, customer_id, billing, contract_value, budget_cost) VALUES
    ('00000000-0000-0000-0000-000000000d71', app_company_id(), '00000000-0000-0000-0000-0000000000c1', 'fixed_fee', 500000, 380000);
  BEGIN
    UPDATE projects SET company_id = '00000000-0000-0000-0000-00000000000b' WHERE dimension_id = '00000000-0000-0000-0000-000000000d71';
    RAISE EXCEPTION 'FAIL T67c: a project moved to another company';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES
    ('00000000-0000-0000-0000-0000000067d1', app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-08-01', 'ETB');
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, project_id) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000067d1', 1, 'Progress claim 1', 1, 100000, '00000000-0000-0000-0000-000000000a40', 100000, '00000000-0000-0000-0000-000000000d71');
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, project_id) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000067d1', 2, 'Planted', 1, 1, '00000000-0000-0000-0000-000000000a40', 1, '00000000-0000-0000-0000-000000000d43');
    RAISE EXCEPTION 'FAIL T67d: a document line named another company''s dimension as its project';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  BEGIN
    UPDATE document_lines SET project_id = '00000000-0000-0000-0000-000000000d72' WHERE document_id = '00000000-0000-0000-0000-0000000067d1';
    RAISE EXCEPTION 'FAIL T67e: a document line named a class as its project';
  EXCEPTION WHEN foreign_key_violation THEN NULL; END;
  RAISE NOTICE 'PASS T67 a project is a project of the company for one of its customers, and document lines name only its projects';
END $$;
-- T68: time entries (041). Logged against a project of the company by one of its members, entered by the person
-- signed in; billed only when billable, on an invoice of the company; a billed entry never changes or goes, until its
-- invoice is void. Company A, as user f1, on T67's project d71; f2 is not a member of company A.
DO $$ BEGIN
  BEGIN
    INSERT INTO time_entries (company_id, project_id, user_id, work_date, hours, created_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000d72', '00000000-0000-0000-0000-0000000000f1', '2027-08-02', 3, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T68a: hours were logged against a class';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO time_entries (company_id, project_id, user_id, work_date, hours, created_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000d71', '00000000-0000-0000-0000-0000000000f2', '2027-08-02', 3, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T68b: hours were logged for someone who is not a member';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO time_entries (company_id, project_id, user_id, work_date, hours, created_by) VALUES
      (app_company_id(), '00000000-0000-0000-0000-000000000d71', '00000000-0000-0000-0000-0000000000f1', '2027-08-02', 25, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T68c: a day had more than 24 hours';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO time_entries (id, company_id, project_id, user_id, work_date, hours, rate, created_by) VALUES
    ('00000000-0000-0000-0000-0000000068e1', app_company_id(), '00000000-0000-0000-0000-000000000d71', '00000000-0000-0000-0000-0000000000f1', '2027-08-02', 6.5, 900, '00000000-0000-0000-0000-0000000000f1'),
    ('00000000-0000-0000-0000-0000000068e2', app_company_id(), '00000000-0000-0000-0000-000000000d71', '00000000-0000-0000-0000-0000000000f1', '2027-08-03', 2, null, '00000000-0000-0000-0000-0000000000f1');
  UPDATE time_entries SET billable = false WHERE id = '00000000-0000-0000-0000-0000000068e2';
  BEGIN
    UPDATE time_entries SET invoice_id = '00000000-0000-0000-0000-0000000067d1' WHERE id = '00000000-0000-0000-0000-0000000068e2';
    RAISE EXCEPTION 'FAIL T68d: non-billable hours were billed';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- Billed on T67's draft invoice: the entry is fixed and stays.
  UPDATE time_entries SET invoice_id = '00000000-0000-0000-0000-0000000067d1' WHERE id = '00000000-0000-0000-0000-0000000068e1';
  BEGIN
    UPDATE time_entries SET hours = 8 WHERE id = '00000000-0000-0000-0000-0000000068e1';
    RAISE EXCEPTION 'FAIL T68e: billed hours were changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM time_entries WHERE id = '00000000-0000-0000-0000-0000000068e1';
    RAISE EXCEPTION 'FAIL T68f: billed hours were removed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  DELETE FROM time_entries WHERE id = '00000000-0000-0000-0000-0000000068e2';   -- unbilled hours can go
  RAISE NOTICE 'PASS T68 hours are logged on a project by a member, billed only when billable, and fixed once billed';
END $$;
-- T69: fixed assets and depreciation (042). An asset uses the company's fixed asset, accumulated depreciation and
-- expense accounts; once depreciated, how it wears out stays; one run per month end with its own entry; never below the
-- residual value. Company A, as user f1.
DO $$ BEGIN
  INSERT INTO accounts (id, company_id, code, name, type, subtype) VALUES
    ('00000000-0000-0000-0000-0000000069a1', app_company_id(), '1569', 'Delivery vans', 'asset', 'fixed_asset'),
    ('00000000-0000-0000-0000-0000000069a2', app_company_id(), '1599', 'Accumulated depreciation, vans', 'asset', 'contra_asset'),
    ('00000000-0000-0000-0000-0000000069a3', app_company_id(), '6799', 'Depreciation, vans', 'expense', 'depreciation');
  BEGIN
    INSERT INTO fixed_assets (company_id, code, name, asset_account_id, depreciation_account_id, expense_account_id, acquired_on, cost, method, useful_life_months, depreciation_starts_on, created_by) VALUES
      (app_company_id(), 'VAN-0', 'Van', '00000000-0000-0000-0000-0000000069a3', '00000000-0000-0000-0000-0000000069a2', '00000000-0000-0000-0000-0000000069a3', '2027-07-15', 60000, 'straight_line', 60, '2027-08-01', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T69a: an asset was kept on an expense account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO fixed_assets (company_id, code, name, asset_account_id, depreciation_account_id, expense_account_id, acquired_on, cost, residual_value, opening_depreciation, method, useful_life_months, depreciation_starts_on, created_by) VALUES
      (app_company_id(), 'VAN-0', 'Van', '00000000-0000-0000-0000-0000000069a1', '00000000-0000-0000-0000-0000000069a2', '00000000-0000-0000-0000-0000000069a3', '2027-07-15', 60000, 50000, 20000, 'straight_line', 60, '2027-08-01', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T69b: residual value and depreciation so far were more than the cost';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO fixed_assets (id, company_id, code, name, asset_account_id, depreciation_account_id, expense_account_id, acquired_on, cost, residual_value, method, useful_life_months, depreciation_starts_on, created_by) VALUES
    ('00000000-0000-0000-0000-0000000069f1', app_company_id(), 'VAN-1', 'Isuzu van', '00000000-0000-0000-0000-0000000069a1', '00000000-0000-0000-0000-0000000069a2', '00000000-0000-0000-0000-0000000069a3', '2027-07-15', 60000, 6000, 'straight_line', 60, '2027-08-01', '00000000-0000-0000-0000-0000000000f1');

  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e691', app_company_id(), 9691, '2027-08-30', 'ETB', 'depreciation', '00000000-0000-0000-0000-0000000069d1');
    RAISE EXCEPTION 'FAIL T69c: a depreciation entry was dated before the month end';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e692', app_company_id(), 9692, '2027-08-31', 'ETB', 'depreciation', '00000000-0000-0000-0000-0000000069d1');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e692', 1, '00000000-0000-0000-0000-0000000069a3', 900, 0, 900),
    (app_company_id(), '00000000-0000-0000-0000-00000000e692', 2, '00000000-0000-0000-0000-0000000069a2', 0, 900, -900);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  BEGIN
    INSERT INTO depreciation_runs (id, company_id, period_end, journal_entry_id, total, created_by) VALUES
      ('00000000-0000-0000-0000-0000000069d0', app_company_id(), '2027-08-31', '00000000-0000-0000-0000-00000000e692', 900, '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T69d: a run named an entry that is not its own';
  EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL; END;
  INSERT INTO depreciation_runs (id, company_id, period_end, journal_entry_id, total, created_by) VALUES
    ('00000000-0000-0000-0000-0000000069d1', app_company_id(), '2027-08-31', '00000000-0000-0000-0000-00000000e692', 900, '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    INSERT INTO asset_depreciation (company_id, run_id, asset_id, amount) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000069d1', '00000000-0000-0000-0000-0000000069f1', 54001);
    RAISE EXCEPTION 'FAIL T69e: an asset was depreciated below its residual value';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO asset_depreciation (company_id, run_id, asset_id, amount) VALUES
    (app_company_id(), '00000000-0000-0000-0000-0000000069d1', '00000000-0000-0000-0000-0000000069f1', 900);

  -- Depreciated: how it wears out stays; its name and keeper may change; the run and its charges stay.
  BEGIN
    UPDATE fixed_assets SET useful_life_months = 36 WHERE id = '00000000-0000-0000-0000-0000000069f1';
    RAISE EXCEPTION 'FAIL T69f: a depreciated asset''s life was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE fixed_assets SET name = 'Isuzu NPR van', custodian = 'Dawit (driver)' WHERE id = '00000000-0000-0000-0000-0000000069f1';
  BEGIN
    UPDATE depreciation_runs SET total = 1 WHERE id = '00000000-0000-0000-0000-0000000069d1';
    RAISE EXCEPTION 'FAIL T69g: a depreciation run was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM asset_depreciation WHERE run_id = '00000000-0000-0000-0000-0000000069d1';
    RAISE EXCEPTION 'FAIL T69h: a depreciation charge was removed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T69 assets use their own accounts, keep how they wear out once depreciated, and runs stay as posted';
END $$;
-- T70: disposing of fixed assets (043). An asset goes once, by its own disposal entry dated on or after it was
-- acquired; after that it never changes. T69's van (cost 60,000, depreciated 900) is sold for 50,000: a loss of 9,100.
DO $$ BEGIN
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e701', app_company_id(), 9701, '2027-07-01', 'ETB', 'asset_disposal', '00000000-0000-0000-0000-0000000069f1');
    RAISE EXCEPTION 'FAIL T70a: an asset was disposed of before it was acquired';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fixed_assets SET status = 'disposed', disposed_on = '2027-09-10' WHERE id = '00000000-0000-0000-0000-0000000069f1';
    RAISE EXCEPTION 'FAIL T70b: an asset was marked disposed without its disposal entry';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
    ('00000000-0000-0000-0000-00000000e702', app_company_id(), 9702, '2027-09-10', 'ETB', 'asset_disposal', '00000000-0000-0000-0000-0000000069f1');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit, amount_txn) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e702', 1, '00000000-0000-0000-0000-0000000069a2', 900, 0, 900),
    (app_company_id(), '00000000-0000-0000-0000-00000000e702', 2, '00000000-0000-0000-0000-000000000a10', 50000, 0, 50000),
    (app_company_id(), '00000000-0000-0000-0000-00000000e702', 3, '00000000-0000-0000-0000-000000000a40', 9100, 0, 9100),
    (app_company_id(), '00000000-0000-0000-0000-00000000e702', 4, '00000000-0000-0000-0000-0000000069a1', 0, 60000, -60000);
  SET CONSTRAINTS ALL IMMEDIATE;
  SET CONSTRAINTS ALL DEFERRED;
  UPDATE fixed_assets SET status = 'disposed', disposed_on = '2027-09-10', disposal_entry_id = '00000000-0000-0000-0000-00000000e702', proceeds = 50000
   WHERE id = '00000000-0000-0000-0000-0000000069f1';
  BEGIN
    UPDATE fixed_assets SET name = 'Old van' WHERE id = '00000000-0000-0000-0000-0000000069f1';
    RAISE EXCEPTION 'FAIL T70c: a disposed asset was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id) VALUES
      ('00000000-0000-0000-0000-00000000e703', app_company_id(), 9703, '2027-09-11', 'ETB', 'asset_disposal', '00000000-0000-0000-0000-0000000069f1');
    RAISE EXCEPTION 'FAIL T70d: an asset was disposed of twice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T70 an asset is disposed of once, by its own entry, and then stays as it was';
END $$;
-- T71: the salesperson on a sales document (044). Only sales documents name one; a salesperson named is an active
-- member of the company. Company A, as user f1 (a member); f2 is not a member of company A; c7 is a supplier.
DO $$ BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, salesperson_id) VALUES
    ('00000000-0000-0000-0000-0000000071d1', app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-08-05', 'ETB', '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, salesperson_id) VALUES
      ('00000000-0000-0000-0000-0000000071d2', app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000000c1', '2027-08-05', 'ETB', '00000000-0000-0000-0000-0000000000f2');
    RAISE EXCEPTION 'FAIL T71a: an invoice named someone who is not a member as its salesperson';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET salesperson_id = '00000000-0000-0000-0000-0000000000f2' WHERE id = '00000000-0000-0000-0000-0000000071d1';
    RAISE EXCEPTION 'FAIL T71b: an invoice''s salesperson was changed to someone who is not a member';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, salesperson_id) VALUES
      ('00000000-0000-0000-0000-0000000071d3', app_company_id(), 'bill', '00000000-0000-0000-0000-0000000000c7', '2027-08-05', 'ETB', '00000000-0000-0000-0000-0000000000f1');
    RAISE EXCEPTION 'FAIL T71c: a bill named a salesperson';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET notes = 'Kept' WHERE id = '00000000-0000-0000-0000-0000000071d1';
  IF (SELECT salesperson_id FROM documents WHERE id = '00000000-0000-0000-0000-0000000071d1') IS DISTINCT FROM '00000000-0000-0000-0000-0000000000f1' THEN
    RAISE EXCEPTION 'FAIL T71d: an invoice lost its salesperson';
  END IF;
  RAISE NOTICE 'PASS T71 only sales documents name a salesperson, and only an active member of the company';
END $$;
-- T72: saved and scheduled reports (045). Saved by the person signed in, an active member; seen only by them; a custom
-- period has its dates, a schedule its next date; the scheduler sees what is due only outside any company. Company A,
-- as user f1; f2 is not a member of company A.
DO $$ BEGIN
  INSERT INTO saved_reports (id, company_id, user_id, name, report, period, schedule, next_send_on) VALUES
    ('00000000-0000-0000-0000-0000000072a1', app_company_id(), '00000000-0000-0000-0000-0000000000f1', 'Monthly P&L', 'profit-and-loss', 'last_month', 'monthly', '2027-09-01');
  BEGIN
    INSERT INTO saved_reports (company_id, user_id, name, report, period) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000f2', 'Not mine', 'balance-sheet', 'this_month');
    RAISE EXCEPTION 'FAIL T72a: a report was saved for someone else';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO saved_reports (company_id, user_id, name, report, period) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000f1', 'No dates', 'balance-sheet', 'custom');
    RAISE EXCEPTION 'FAIL T72b: a custom period was saved without its dates';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO saved_reports (company_id, user_id, name, report, period, schedule) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000f1', 'Never sent', 'balance-sheet', 'this_month', 'weekly');
    RAISE EXCEPTION 'FAIL T72c: a schedule was saved without its next date';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO saved_reports (company_id, user_id, name, report, period) VALUES
      (app_company_id(), '00000000-0000-0000-0000-0000000000f1', 'Payroll', 'payroll', 'this_month');
    RAISE EXCEPTION 'FAIL T72d: a report that does not exist was saved';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE saved_reports SET user_id = '00000000-0000-0000-0000-0000000000f2' WHERE id = '00000000-0000-0000-0000-0000000072a1';
    RAISE EXCEPTION 'FAIL T72e: a saved report was handed to someone else';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;
  -- Someone else in the same company does not see it.
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM saved_reports) THEN
    RAISE EXCEPTION 'FAIL T72f: another person saw a saved report';
  END IF;
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  IF EXISTS (SELECT 1 FROM saved_reports_due('2027-09-01')) THEN
    RAISE EXCEPTION 'FAIL T72g: due reports were listed inside a company''s context';
  END IF;
  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  IF (SELECT array_agg(saved_report_id) FROM saved_reports_due('2027-09-01')) IS DISTINCT FROM ARRAY['00000000-0000-0000-0000-0000000072a1'::uuid] THEN
    RAISE EXCEPTION 'FAIL T72h: the scheduler did not see exactly the report that is due';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T72 a saved report is its owner''s alone, saved by them, and the scheduler sees only what is due';
END $$;
-- T73: invitation links (046). Made only by an owner or admin of the company, for an open invitation, lasting at most
-- 14 days; the app reads only its own company's links and writes none; looked up and used once only outside any company's context;
-- accepting is the invited person's own change. Company A, as its owner f1; f9 is invited; f2 owns company B.
DO $$
DECLARE
  hash text := repeat('ab', 32);
  m uuid := '00000000-0000-0000-0000-0000000073e1';
  n int;
BEGIN
  INSERT INTO users (id, name, email, password) VALUES ('00000000-0000-0000-0000-0000000000f9', 'Selam', 'selam@example.test', 'not-usable');
  INSERT INTO memberships (id, company_id, user_id, role, status, invited_at) VALUES
    (m, app_company_id(), '00000000-0000-0000-0000-0000000000f9', 'accountant', 'invited', now());
  BEGIN
    PERFORM invitation_issue(m, hash, true, now() + interval '30 days');
    RAISE EXCEPTION 'FAIL T73a: an invitation link lasted more than 14 days';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    PERFORM invitation_issue((SELECT id FROM memberships WHERE company_id = '00000000-0000-0000-0000-00000000000b' LIMIT 1), hash, true, now() + interval '7 days');
    RAISE EXCEPTION 'FAIL T73b: a link was made for another company''s membership';
  EXCEPTION WHEN check_violation THEN NULL; END;
  PERFORM invitation_issue(m, hash, true, now() + interval '7 days');
  BEGIN
    UPDATE invitation_tokens SET expires_at = now() + interval '1 year';
    RAISE EXCEPTION 'FAIL T73c: the app wrote an invitation link';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  IF (SELECT count(*) FROM invitation_tokens WHERE membership_id = m) <> 1 THEN
    RAISE EXCEPTION 'FAIL T73c: the company does not see its own link';
  END IF;
  IF EXISTS (SELECT 1 FROM invitation_lookup(hash)) OR EXISTS (SELECT 1 FROM invitation_take(hash)) THEN
    RAISE EXCEPTION 'FAIL T73d: a link was read or used inside a company''s context';
  END IF;

  -- Opened by someone not signed in: seen, then used once.
  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  -- Company A goes by its trading name, set by an earlier check.
  IF (SELECT role || '/' || company_name || '/' || email || '/' || new_account FROM invitation_lookup(hash)) IS DISTINCT FROM 'accountant/A Trading/selam@example.test/true' THEN
    RAISE EXCEPTION 'FAIL T73e: the link did not show its invitation';
  END IF;
  SELECT count(*) INTO n FROM invitation_take(hash);
  IF n <> 1 OR EXISTS (SELECT 1 FROM invitation_take(hash)) OR EXISTS (SELECT 1 FROM invitation_lookup(hash)) THEN
    RAISE EXCEPTION 'FAIL T73f: a link was not used exactly once';
  END IF;

  -- Accepted by the invited person themselves; as an accountant they cannot make links.
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f9', true);
  UPDATE memberships SET status = 'active' WHERE id = m;
  IF (SELECT status FROM memberships WHERE id = m) <> 'active' THEN
    RAISE EXCEPTION 'FAIL T73g: the invited person could not accept';
  END IF;
  BEGIN
    PERFORM invitation_issue(m, repeat('cd', 32), false, now() + interval '7 days');
    RAISE EXCEPTION 'FAIL T73h: an accountant made an invitation link';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM invitation_tokens) THEN
    RAISE EXCEPTION 'FAIL T73i: company B saw company A''s invitation links';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T73 an invitation link is made by an owner or admin, seen only by its company, used once, and accepted by the invited person';
END $$;
-- T74: custom roles (047). Only a custom role has permissions of its own, as a list of "module.action" or
-- "module.*", never "*". Company A, as its owner f1; f9 is the accountant who joined in T73.
DO $$
DECLARE
  m uuid := '00000000-0000-0000-0000-0000000073e1';
BEGIN
  BEGIN
    UPDATE memberships SET permissions = '["invoices.view"]' WHERE id = m;
    RAISE EXCEPTION 'FAIL T74a: an accountant got permissions of their own';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE memberships SET role = 'custom', permissions = '["*"]' WHERE id = m;
    RAISE EXCEPTION 'FAIL T74b: a custom role was given every permission';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE memberships SET role = 'custom', permissions = '["invoices.view", 5]' WHERE id = m;
    RAISE EXCEPTION 'FAIL T74c: a permission that is not a name was stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE memberships SET role = 'custom', permissions = '{"invoices": "view"}' WHERE id = m;
    RAISE EXCEPTION 'FAIL T74d: permissions that are not a list were stored';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO memberships (company_id, user_id, role, status, permissions, invited_at)
      VALUES (app_company_id(), '00000000-0000-0000-0000-0000000000f2', 'viewer', 'invited', '["reports.view"]', now());
    RAISE EXCEPTION 'FAIL T74e: a viewer was invited with permissions of their own';
  EXCEPTION WHEN check_violation THEN NULL; END;

  UPDATE memberships SET role = 'custom', permissions = '["invoices.view", "reports.*"]' WHERE id = m;
  IF (SELECT role || ' ' || permissions::text FROM memberships WHERE id = m) <> 'custom ["invoices.view", "reports.*"]' THEN
    RAISE EXCEPTION 'FAIL T74f: a custom role did not keep its permissions';
  END IF;
  UPDATE memberships SET role = 'accountant', permissions = '[]' WHERE id = m;
  RAISE NOTICE 'PASS T74 only a custom role has permissions of its own, each a permission name, never every permission';
END $$;
-- T75: invoice reminders (048). Only for a posted invoice of the company with something owed; kept as sent; a
-- scheduled step goes once; another company sees none; the scheduler's list is empty inside a company.
-- Company A as its owner f1; invoice d4 was posted in T40 with 100 owed.
DO $$
DECLARE
  inv constant uuid := '00000000-0000-0000-0000-0000000000d4';
  draft uuid;
BEGIN
  IF (SELECT status FROM documents WHERE id = inv) NOT IN ('posted', 'part_paid') OR (SELECT amount_due FROM documents WHERE id = inv) <= 0 THEN
    RAISE EXCEPTION 'FAIL T75: fixture invoice d4 is no longer posted and owed';
  END IF;
  SELECT id INTO draft FROM documents WHERE company_id = app_company_id() AND type = 'invoice' AND status = 'draft' LIMIT 1;
  BEGIN
    INSERT INTO invoice_reminders (company_id, document_id, kind, sent_to, sent_by) VALUES (app_company_id(), draft, 'manual', 'buyer@example.test', app_user_id());
    RAISE EXCEPTION 'FAIL T75a: a reminder was recorded for a draft';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO invoice_reminders (company_id, document_id, kind, sent_to) VALUES (app_company_id(), inv, 'manual', 'buyer@example.test');
    RAISE EXCEPTION 'FAIL T75b: a reminder sent by hand named no sender';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO invoice_reminders (company_id, document_id, kind, sent_to, sent_by) VALUES (app_company_id(), inv, 'manual', 'buyer@example.test', app_user_id());
  INSERT INTO invoice_reminders (company_id, document_id, kind, step, sent_to) VALUES (app_company_id(), inv, 'scheduled', 7, 'buyer@example.test');
  BEGIN
    INSERT INTO invoice_reminders (company_id, document_id, kind, step, sent_to) VALUES (app_company_id(), inv, 'scheduled', 7, 'buyer@example.test');
    RAISE EXCEPTION 'FAIL T75c: a scheduled step went twice';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    UPDATE invoice_reminders SET sent_to = 'someone@else.test' WHERE document_id = inv;
    RAISE EXCEPTION 'FAIL T75d: a sent reminder was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM invoice_reminders WHERE document_id = inv;
    RAISE EXCEPTION 'FAIL T75e: a sent reminder was removed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  IF EXISTS (SELECT 1 FROM invoice_reminders_due(current_date + 400)) THEN
    RAISE EXCEPTION 'FAIL T75f: the scheduler''s list was read inside a company';
  END IF;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM invoice_reminders) THEN
    RAISE EXCEPTION 'FAIL T75g: company B saw company A''s reminders';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T75 a reminder is for an owed posted invoice, kept as sent, once per step, and seen only by its company';
END $$;
-- T76: project milestones (049). A milestone belongs to a project of the company and is billed on an invoice to the
-- project's customer; once billed on an invoice that stands it is fixed and kept, until that invoice is voided.
-- Company A as its owner f1; project d71 (customer c1) and its draft invoice 67d1 come from T67.
DO $$
DECLARE
  m uuid;
BEGIN
  BEGIN
    INSERT INTO project_milestones (company_id, project_id, name, amount) VALUES (app_company_id(), '00000000-0000-0000-0000-000000000d72', 'Fit-out', 100);
    RAISE EXCEPTION 'FAIL T76a: a milestone was put on a class, not a project';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO project_milestones (company_id, project_id, name, amount, due_on) VALUES
    (app_company_id(), '00000000-0000-0000-0000-000000000d71', 'Foundations', 100000, '2027-09-30') RETURNING id INTO m;

  INSERT INTO contacts (id, company_id, name, is_customer) VALUES ('00000000-0000-0000-0000-0000000076c1', app_company_id(), 'Another customer', true);
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES
    ('00000000-0000-0000-0000-0000000076d1', app_company_id(), 'invoice', '00000000-0000-0000-0000-0000000076c1', '2027-08-01', 'ETB');
  BEGIN
    UPDATE project_milestones SET invoice_id = '00000000-0000-0000-0000-0000000076d1' WHERE id = m;
    RAISE EXCEPTION 'FAIL T76b: a milestone was billed to someone other than the project''s customer';
  EXCEPTION WHEN check_violation THEN NULL; END;

  UPDATE project_milestones SET invoice_id = '00000000-0000-0000-0000-0000000067d1' WHERE id = m;
  BEGIN
    UPDATE project_milestones SET amount = 1 WHERE id = m;
    RAISE EXCEPTION 'FAIL T76c: a billed milestone''s amount changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM project_milestones WHERE id = m;
    RAISE EXCEPTION 'FAIL T76d: a billed milestone was removed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE project_milestones SET due_on = '2027-10-15' WHERE id = m;   -- the date is only a plan

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM project_milestones) THEN
    RAISE EXCEPTION 'FAIL T76e: company B saw company A''s milestones';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);

  -- Voiding the invoice opens the milestone again.
  UPDATE documents SET status = 'void' WHERE id = '00000000-0000-0000-0000-0000000067d1';
  UPDATE project_milestones SET amount = 120000, invoice_id = NULL WHERE id = m;
  DELETE FROM project_milestones WHERE id = m;
  RAISE NOTICE 'PASS T76 a milestone is billed once to the project''s customer, then kept as billed until its invoice is voided';
END $$;
-- T77: retention held and released (050). Held on a posted invoice of the company, never more than it owes, with the
-- company's own retention entry; it lowers what the invoice owes and a release puts it back, once; kept, never removed;
-- the invoice is not voided or re-posted meanwhile. Company A as its owner f1; invoice d4 (posted, owed) from T40.
DO $$
DECLARE
  inv constant uuid := '00000000-0000-0000-0000-0000000000d4';
  r constant uuid := '00000000-0000-0000-0000-0000000077a1';
  owed numeric;
BEGIN
  SELECT amount_due INTO owed FROM documents WHERE id = inv;
  IF owed IS NULL OR owed < 10 THEN
    RAISE EXCEPTION 'FAIL T77: fixture invoice d4 no longer owes at least 10';
  END IF;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id)
    VALUES ('00000000-0000-0000-0000-00000000e771', app_company_id(), 9771, '2027-07-15', 'ETB', 'retention', r);
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e771', 1, '00000000-0000-0000-0000-000000000a11', 10, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e771', 2, '00000000-0000-0000-0000-000000000a40', 0, 10);

  BEGIN
    INSERT INTO invoice_retentions (id, company_id, document_id, amount, held_on, held_entry_id, held_by)
      VALUES (r, app_company_id(), inv, owed + 1, '2027-07-15', '00000000-0000-0000-0000-00000000e771', app_user_id());
    RAISE EXCEPTION 'FAIL T77a: more retention was held than the invoice owes';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO invoice_retentions (id, company_id, document_id, amount, held_on, held_entry_id, held_by)
      VALUES (r, app_company_id(), inv, 10, '2027-07-15', '00000000-0000-0000-0000-00000000e041', app_user_id());
    RAISE EXCEPTION 'FAIL T77b: retention was held with an entry that is not its own';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO invoice_retentions (id, company_id, document_id, amount, held_on, held_entry_id, held_by)
    VALUES (r, app_company_id(), inv, 10, '2027-07-15', '00000000-0000-0000-0000-00000000e771', app_user_id());
  IF (SELECT amount_due FROM documents WHERE id = inv) <> owed - 10 THEN
    RAISE EXCEPTION 'FAIL T77c: holding retention did not lower what the invoice owes';
  END IF;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = inv;
    RAISE EXCEPTION 'FAIL T77d: an invoice holding retention was voided';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE invoice_retentions SET amount = 5 WHERE id = r;
    RAISE EXCEPTION 'FAIL T77e: held retention was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM invoice_retentions WHERE id = r;
    RAISE EXCEPTION 'FAIL T77f: held retention was removed';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN NULL; END;   -- the app may not delete at all

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM invoice_retentions) THEN
    RAISE EXCEPTION 'FAIL T77g: company B saw company A''s retention';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);

  -- Released with its own entry: owed again, once.
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type, source_id)
    VALUES ('00000000-0000-0000-0000-00000000e772', app_company_id(), 9772, '2027-09-30', 'ETB', 'retention', r);
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e772', 1, '00000000-0000-0000-0000-000000000a40', 10, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e772', 2, '00000000-0000-0000-0000-000000000a11', 0, 10);
  UPDATE invoice_retentions SET released_on = '2027-09-30', released_entry_id = '00000000-0000-0000-0000-00000000e772', released_by = app_user_id() WHERE id = r;
  IF (SELECT amount_due FROM documents WHERE id = inv) <> owed THEN
    RAISE EXCEPTION 'FAIL T77h: releasing retention did not make it owed again';
  END IF;
  BEGIN
    UPDATE invoice_retentions SET released_on = '2027-10-01' WHERE id = r;
    RAISE EXCEPTION 'FAIL T77i: retention was released twice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T77 retention is held on an owed invoice with its own entry, released once, and kept';
END $$;
-- T78: turnover tax returns (052). A TOT return is filed like the others, beside a VAT return of the same days, and its
-- filed period locks TOT payable. Company A as its owner f1 (a VAT return covers September 2027 from T66; a10 the bank).
DO $$
DECLARE
  tot uuid := '00000000-0000-0000-0000-0000000078a1';
BEGIN
  INSERT INTO accounts (id, company_id, code, name, type, subtype, system_key) VALUES
    (tot, app_company_id(), 'T78', 'Turnover tax payable', 'liability', 'tax_payable', 'tot_payable');
  BEGIN
    INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
      (app_company_id(), 'sales_tax', '2027-09-01', '2027-09-30', '2027-10-15', 0, 0, 0, '{}', app_user_id());
    RAISE EXCEPTION 'FAIL T78a: a return of an unknown kind was filed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO tax_returns (company_id, kind, period_start, period_end, filed_on, output_tax, input_tax, net, figures, filed_by) VALUES
    (app_company_id(), 'tot', '2027-09-01', '2027-09-30', '2027-10-15', 200, 0, 200, '{"sales": {}}', app_user_id());

  BEGIN
    INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
      ('00000000-0000-0000-0000-00000000e781', app_company_id(), 9781, '2027-09-10', 'ETB', 'manual');
    INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
      (app_company_id(), '00000000-0000-0000-0000-00000000e781', 1, tot, 0, 50);
    RAISE EXCEPTION 'FAIL T78b: TOT payable moved inside a filed TOT period';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES
    ('00000000-0000-0000-0000-00000000e782', app_company_id(), 9782, '2027-11-10', 'ETB', 'manual');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), '00000000-0000-0000-0000-00000000e782', 1, '00000000-0000-0000-0000-000000000a10', 50, 0),
    (app_company_id(), '00000000-0000-0000-0000-00000000e782', 2, tot, 0, 50);
  IF tax_return_kind_of_account('tot_payable') IS DISTINCT FROM 'tot' THEN
    RAISE EXCEPTION 'FAIL T78c: TOT payable is not a TOT return''s account';
  END IF;
  RAISE NOTICE 'PASS T78 a turnover tax return is filed beside VAT and locks TOT payable for its period';
END $$;
-- T79: subscriptions (053). The application changes no plan or status and reads no billing events; time moves a
-- subscription only towards less, outside any company: a trial past its end is suspended, a paid one past its date is
-- past due, then suspended after the grace days. Two organisations of their own (a7, a8), so no other check is touched.
RESET ROLE;
SELECT set_config('t79.a0_status', (SELECT status || '|' || coalesce(trial_ends_at::text, '') FROM organisations WHERE id = '00000000-0000-0000-0000-0000000000a0'), false) \gset ignore_
INSERT INTO organisations (id, name, status, trial_ends_at) VALUES ('00000000-0000-0000-0000-0000000000a7', 'Trial over Org', 'trial', '2027-01-10');
INSERT INTO organisations (id, name) VALUES ('00000000-0000-0000-0000-0000000000a8', 'Paid Org');
UPDATE organisations SET status = 'active', paid_until = '2027-01-31' WHERE id = '00000000-0000-0000-0000-0000000000a8';
SET ROLE ledgerly_web;
DO $$
BEGIN
  BEGIN
    UPDATE organisations SET status = 'active' WHERE id = '00000000-0000-0000-0000-0000000000a0';
    RAISE EXCEPTION 'FAIL T79a: the application changed a subscription''s status';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    PERFORM 1 FROM subscription_events;
    RAISE EXCEPTION 'FAIL T79b: the application read the billing events';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    PERFORM subscriptions_tick('2027-02-15', 14);
    RAISE EXCEPTION 'FAIL T79c: subscriptions moved inside a company''s context';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;

  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  PERFORM subscriptions_tick('2027-02-05', 14);   -- the trial ended on 10 Jan; paid to 31 Jan: now past due
  PERFORM subscriptions_tick('2027-02-05', 14);   -- nothing more on the same day
  PERFORM subscriptions_tick('2027-02-20', 14);   -- 31 Jan + 14 days grace is over: suspended
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
END $$;
RESET ROLE;
DO $$
BEGIN
  IF (SELECT status FROM organisations WHERE id = '00000000-0000-0000-0000-0000000000a7') <> 'suspended' THEN
    RAISE EXCEPTION 'FAIL T79d: a trial past its end was not suspended';
  END IF;
  IF (SELECT status FROM organisations WHERE id = '00000000-0000-0000-0000-0000000000a8') <> 'suspended' THEN
    RAISE EXCEPTION 'FAIL T79e: an unpaid subscription was not suspended after the grace days';
  END IF;
  IF (SELECT string_agg(action, ',' ORDER BY id) FROM subscription_events WHERE organisation_id IN ('00000000-0000-0000-0000-0000000000a7', '00000000-0000-0000-0000-0000000000a8'))
     <> 'trial_ended,payment_overdue,suspended_for_non_payment' THEN
    RAISE EXCEPTION 'FAIL T79f: the billing events do not tell what happened';
  END IF;
  IF (SELECT status || '|' || coalesce(trial_ends_at::text, '') FROM organisations WHERE id = '00000000-0000-0000-0000-0000000000a0') <> current_setting('t79.a0_status') THEN
    RAISE EXCEPTION 'FAIL T79g: an organisation with nothing due was moved';
  END IF;
  RAISE NOTICE 'PASS T79 subscriptions change only through billing, and time moves them only towards less, outside any company';
END $$;
SET ROLE ledgerly_web;
-- T80: tax return reminders (054). Once per period and stage, kept as sent, seen only by the company; the scheduler's
-- list of companies is read only outside any company. Company A as its owner f1.
DO $$
BEGIN
  INSERT INTO tax_reminders (company_id, kind, period_start, period_end, due_on, stage, sent_to)
    VALUES (app_company_id(), 'vat', '2027-08-01', '2027-08-31', '2027-09-30', 'due_soon', 2);
  BEGIN
    INSERT INTO tax_reminders (company_id, kind, period_start, period_end, due_on, stage, sent_to)
      VALUES (app_company_id(), 'vat', '2027-08-01', '2027-08-31', '2027-09-30', 'due_soon', 2);
    RAISE EXCEPTION 'FAIL T80a: a period was reminded twice at the same stage';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    UPDATE tax_reminders SET sent_to = 0 WHERE company_id = app_company_id();
    RAISE EXCEPTION 'FAIL T80b: a sent reminder was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  IF EXISTS (SELECT 1 FROM tax_reminder_companies()) THEN
    RAISE EXCEPTION 'FAIL T80c: the scheduler''s companies were read inside a company';
  END IF;

  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  IF NOT EXISTS (SELECT 1 FROM tax_reminder_companies() WHERE company_id = '00000000-0000-0000-0000-00000000000a') THEN
    RAISE EXCEPTION 'FAIL T80d: a company in use is not reminded';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM tax_reminders) THEN
    RAISE EXCEPTION 'FAIL T80e: company B saw company A''s tax reminders';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T80 a tax return is reminded once per stage, kept, seen by its company, and listed only to the scheduler';
END $$;
-- T81: stock issued to a project (055). An issue takes stock out and names a project of the company; no other move
-- names a project. Company A as its owner f1: item b1 in warehouse a1 (T11), project d71 (T67), d43 company B's.
DO $$
DECLARE
  b1 constant uuid := '00000000-0000-0000-0000-0000000000b1';
  a1 constant uuid := '00000000-0000-0000-0000-0000000000a1';
BEGIN
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, project_id)
      VALUES (app_company_id(), b1, a1, '2027-08-10', 'project_issue', 1, 0, '00000000-0000-0000-0000-000000000d71');
    RAISE EXCEPTION 'FAIL T81a: an issue to a project brought stock in';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost)
      VALUES (app_company_id(), b1, a1, '2027-08-10', 'project_issue', -1, 0);
    RAISE EXCEPTION 'FAIL T81b: stock was issued to no project';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, project_id)
      VALUES (app_company_id(), b1, a1, '2027-08-10', 'project_issue', -1, 0, '00000000-0000-0000-0000-000000000d43');
    RAISE EXCEPTION 'FAIL T81c: stock was issued to another company''s dimension';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, project_id)
      VALUES (app_company_id(), b1, a1, '2027-08-10', 'adjustment', -1, 0, '00000000-0000-0000-0000-000000000d71');
    RAISE EXCEPTION 'FAIL T81d: an adjustment named a project';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, project_id)
    VALUES (app_company_id(), b1, a1, '2027-08-10', 'project_issue', -1, 0, '00000000-0000-0000-0000-000000000d71');
  RAISE NOTICE 'PASS T81 stock is issued out of a warehouse to a project of the company, and only an issue names a project';
END $$;
-- T82: quotes and sales orders (056). A quote owes nothing; a document made from one names an accepted quote or a
-- confirmed order of the same company and customer, one live document each; the quote is converted only once it
-- exists, and the link never changes; an accepted quote can still be cancelled; quotes past their date are listed only
-- to the scheduler, and another company never sees them. Company A as its owner f1, customers c1 and c4, invoice d1 (T9).
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c4 constant uuid := '00000000-0000-0000-0000-0000000000c4';
  q  constant uuid := '00000000-0000-0000-0000-000000000e82';
  so constant uuid := '00000000-0000-0000-0000-000000000e83';
  q2 constant uuid := '00000000-0000-0000-0000-000000000e84';
  q3 constant uuid := '00000000-0000-0000-0000-000000000e85';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, due_date, currency, total)
    VALUES (q, app_company_id(), 'quote', c1, '2027-08-01', '2027-08-15', 'ETB', 1000);
  BEGIN
    UPDATE documents SET amount_due = 1000 WHERE id = q;
    RAISE EXCEPTION 'FAIL T82a: a quote was owed money';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id)
      VALUES (app_company_id(), 'invoice', c1, '2027-08-02', 'ETB', q);
    RAISE EXCEPTION 'FAIL T82b: an invoice was made from a quote nobody accepted';
  EXCEPTION WHEN check_violation THEN NULL; END;

  UPDATE documents SET status = 'accepted', number = 'QT-T82-1' WHERE id = q;
  BEGIN
    UPDATE documents SET status = 'converted' WHERE id = q;
    RAISE EXCEPTION 'FAIL T82c: a quote was converted with nothing made from it';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id)
      VALUES (app_company_id(), 'sales_order', c4, '2027-08-02', 'ETB', q);
    RAISE EXCEPTION 'FAIL T82d: an order was made from another customer''s quote';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id)
      VALUES (app_company_id(), 'invoice', c1, '2027-08-02', 'ETB', '00000000-0000-0000-0000-0000000000d1');
    RAISE EXCEPTION 'FAIL T82e: an invoice was made from an invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id)
    VALUES (so, app_company_id(), 'sales_order', c1, '2027-08-02', 'ETB', q);
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id)
      VALUES (app_company_id(), 'invoice', c1, '2027-08-02', 'ETB', q);
    RAISE EXCEPTION 'FAIL T82f: a second document was made from one quote';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  UPDATE documents SET status = 'converted' WHERE id = q;
  BEGIN
    UPDATE documents SET source_document_id = NULL WHERE id = so;
    RAISE EXCEPTION 'FAIL T82g: the quote an order was made from was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = q;
    RAISE EXCEPTION 'FAIL T82h: a converted quote was cancelled';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- An accepted quote, and a confirmed order, can still be cancelled.
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES (q2, app_company_id(), 'quote', c1, '2027-08-01', 'ETB');
  UPDATE documents SET status = 'accepted', number = 'QT-T82-2' WHERE id = q2;
  UPDATE documents SET status = 'void' WHERE id = q2;

  -- Expiry: a sent quote past its date is listed to the scheduler only.
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, due_date, currency, status)
    VALUES (q3, app_company_id(), 'quote', 'QT-T82-3', c1, '2027-08-01', '2027-08-15', 'ETB', 'draft');
  UPDATE documents SET status = 'sent' WHERE id = q3;
  IF EXISTS (SELECT 1 FROM quotes_expiring('2099-01-01')) THEN
    RAISE EXCEPTION 'FAIL T82i: the quotes expiring were read inside a company';
  END IF;
  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  IF NOT EXISTS (SELECT 1 FROM quotes_expiring('2027-08-16') WHERE document_id = q3)
     OR EXISTS (SELECT 1 FROM quotes_expiring('2027-08-15') WHERE document_id = q3)
     OR EXISTS (SELECT 1 FROM quotes_expiring('2099-01-01') WHERE document_id IN (q, q2)) THEN
    RAISE EXCEPTION 'FAIL T82j: the scheduler did not list exactly the sent quotes past their date';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM documents WHERE id IN (q, so, q2, q3)) THEN
    RAISE EXCEPTION 'FAIL T82k: company B saw company A''s quotes';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T82 quotes owe nothing, convert once into a document of the same customer, and expire only through the scheduler';
END $$;
-- T83: open invoices and bills from the old books (057). Only an invoice or a bill is one, dated on or before the
-- company's conversion date (none without one), and it stays one; it is never sent to the tax authority. Company A
-- (no conversion date) as f1, then a company of its own with a conversion date, as the owner role.
DO $$ BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, number, contact_id, issue_date, currency, total, amount_due, opening)
      VALUES (app_company_id(), 'invoice', 'OLD-T83-1', '00000000-0000-0000-0000-0000000000c1', '2026-06-01', 'ETB', 100, 100, true);
    RAISE EXCEPTION 'FAIL T83a: an invoice from the old books came in without a conversion date';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
RESET ROLE;
DO $$
DECLARE
  e  constant uuid := '00000000-0000-0000-0000-000000000e83';
  k  constant uuid := '00000000-0000-0000-0000-000000000c83';
  d  constant uuid := '00000000-0000-0000-0000-000000000d83';
BEGIN
  INSERT INTO companies (id, organisation_id, legal_name, country_code, base_currency, conversion_date)
    VALUES (e, '00000000-0000-0000-0000-0000000000a0', 'Company E83', 'ET', 'ETB', '2027-06-30');
  INSERT INTO contacts (id, company_id, name, is_customer) VALUES (k, e, 'Old customer', true);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, due_date, currency, total, amount_due, opening)
    VALUES (d, e, 'invoice', 'OLD-T83-2', k, '2027-05-01', '2027-05-31', 'ETB', 100, 100, true);
  BEGIN
    INSERT INTO documents (company_id, type, number, contact_id, issue_date, currency, total, amount_due, opening)
      VALUES (e, 'invoice', 'OLD-T83-3', k, '2027-07-01', 'ETB', 100, 100, true);
    RAISE EXCEPTION 'FAIL T83b: an invoice dated after the conversion date came in from the old books';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, opening)
      VALUES (e, 'quote', k, '2027-05-01', 'ETB', true);
    RAISE EXCEPTION 'FAIL T83c: a quote came in from the old books';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE documents SET opening = false WHERE id = d;
    RAISE EXCEPTION 'FAIL T83d: an invoice from the old books became an ordinary one';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO einvoice_submissions (company_id, document_id, authority) VALUES (e, d, 'SANDBOX');
    RAISE EXCEPTION 'FAIL T83e: an invoice from the old books was sent to the tax authority';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T83 invoices and bills from the old books are dated by the conversion date, stay so, and are never sent to the tax authority';
END $$;
SET ROLE ledgerly_web;
-- T84: goods back on credit notes (058). Only a credit note says its goods came back; what comes back on an invoice's
-- credit notes is never more, item by item, than the invoice sold, and goods sent out again when one is voided count.
-- Company A as its owner f1: item b1 in warehouse a1 (T11), customer c1 and posted invoice d1 (T9).
DO $$
DECLARE
  b1  constant uuid := '00000000-0000-0000-0000-0000000000b1';
  a1  constant uuid := '00000000-0000-0000-0000-0000000000a1';
  c1  constant uuid := '00000000-0000-0000-0000-0000000000c1';
  inv constant uuid := '00000000-0000-0000-0000-0000000000d1';   -- posted (T9): only a posted invoice is credited
  cn  constant uuid := '00000000-0000-0000-0000-000000000c84';
BEGIN
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost)
    VALUES (app_company_id(), b1, a1, '2027-08-11', 'adjustment', 5, 10);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
    VALUES (app_company_id(), b1, a1, '2027-08-11', 'sale', -2, 0, inv);
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, original_document_id, restock)
    VALUES (cn, app_company_id(), 'credit_note', c1, '2027-08-12', 'ETB', inv, true);
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, restock) VALUES (app_company_id(), 'invoice', c1, '2027-08-12', 'ETB', true);
    RAISE EXCEPTION 'FAIL T84a: an invoice said its goods came back';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
    VALUES (app_company_id(), b1, a1, '2027-08-12', 'sale_return', 1, 0, cn, 10);
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
      VALUES (app_company_id(), b1, a1, '2027-08-12', 'sale_return', 2, 0, cn, 20);
    RAISE EXCEPTION 'FAIL T84b: more came back than the invoice sold';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- The return sent out again (a voided credit note): that quantity may come back once more.
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
    VALUES (app_company_id(), b1, a1, '2027-08-13', 'sale', -1, 0, cn);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
    VALUES (app_company_id(), b1, a1, '2027-08-13', 'sale_return', 2, 0, cn, 20);
  RAISE NOTICE 'PASS T84 goods come back only on credit notes, never more than their invoice sold';
END $$;
-- T85: goods back to the supplier on supplier credits (059). A supplier credit may say its goods went back; what goes
-- back on a bill's supplier credits is never more, item by item, than the bill brought in, and goods brought in again
-- when one is voided count. Company A as f1: item b1 in warehouse a1 (T11), posted bill d9 of supplier c8 (T47).
DO $$
DECLARE
  b1  constant uuid := '00000000-0000-0000-0000-0000000000b1';
  a1  constant uuid := '00000000-0000-0000-0000-0000000000a1';
  d9  constant uuid := '00000000-0000-0000-0000-0000000000d9';
  vc  constant uuid := '00000000-0000-0000-0000-000000000c85';
BEGIN
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
    VALUES (app_company_id(), b1, a1, '2027-08-14', 'purchase', 3, 10, d9);
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, original_document_id, restock)
    VALUES (vc, app_company_id(), 'vendor_credit', '00000000-0000-0000-0000-0000000000c8', '2027-08-15', 'ETB', d9, true);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
    VALUES (app_company_id(), b1, a1, '2027-08-15', 'purchase_return', -1, 0, vc);
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
      VALUES (app_company_id(), b1, a1, '2027-08-15', 'purchase_return', -3, 0, vc);
    RAISE EXCEPTION 'FAIL T85a: more went back to the supplier than the bill brought in';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- The return brought in again (a voided supplier credit): that quantity may go back once more.
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
    VALUES (app_company_id(), b1, a1, '2027-08-16', 'purchase', 1, 0, vc, 10);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id)
    VALUES (app_company_id(), b1, a1, '2027-08-16', 'purchase_return', -3, 0, vc);
  RAISE NOTICE 'PASS T85 goods go back to the supplier only within what their bill brought in';
END $$;
-- T86: landed costs (060). A landed-cost move adds value only, onto stock on hand; a landed cost joins two posted bills
-- of the company in an entry of its own, never spreads a cost bill beyond its amount before tax, and is never changed;
-- another company does not see it. Company A as f1: item b1 in warehouse a1 (T11), posted bill d9 (T47), accounts a40, a22.
DO $$
DECLARE
  b1  constant uuid := '00000000-0000-0000-0000-0000000000b1';
  a1  constant uuid := '00000000-0000-0000-0000-0000000000a1';
  d9  constant uuid := '00000000-0000-0000-0000-0000000000d9';
  cb  constant uuid := '00000000-0000-0000-0000-000000000d86';
  e1  constant uuid := '00000000-0000-0000-0000-00000000e861';
  e2  constant uuid := '00000000-0000-0000-0000-00000000e862';
  b86 constant uuid := '00000000-0000-0000-0000-000000000b86';
  before numeric;
BEGIN
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES (e1, app_company_id(), 9861, '2027-08-17', 'ETB', 'bill');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), e1, 1, '00000000-0000-0000-0000-000000000a40', 300, 0), (app_company_id(), e1, 2, '00000000-0000-0000-0000-000000000a22', 0, 300);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, subtotal, total, amount_due)
    VALUES (cb, app_company_id(), 'bill', 'BILL-T86', '00000000-0000-0000-0000-0000000000c8', '2027-08-17', 'ETB', 300, 300, 300);
  UPDATE documents SET status = 'posted', journal_entry_id = e1 WHERE id = cb;
  INSERT INTO journal_entries (id, company_id, number, entry_date, currency, source_type) VALUES (e2, app_company_id(), 9862, '2027-08-17', 'ETB', 'landed_cost');
  INSERT INTO journal_lines (company_id, journal_entry_id, line_no, account_id, debit, credit) VALUES
    (app_company_id(), e2, 1, '00000000-0000-0000-0000-000000000a40', 200, 0), (app_company_id(), e2, 2, '00000000-0000-0000-0000-000000000a22', 0, 200);

  -- The moves: value only, above zero, onto stock on hand.
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
      VALUES (app_company_id(), b1, a1, '2027-08-17', 'landed_cost', 1, 0, cb, 5);
    RAISE EXCEPTION 'FAIL T86a: a landed cost moved a quantity';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
      VALUES (app_company_id(), b1, a1, '2027-08-17', 'landed_cost', 0, 0, cb, 0);
    RAISE EXCEPTION 'FAIL T86b: a landed cost added no value';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO items (id, company_id, name, type) VALUES (b86, app_company_id(), 'Nothing on hand', 'inventory');
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
      VALUES (app_company_id(), b86, a1, '2027-08-17', 'landed_cost', 0, 0, cb, 5);
    RAISE EXCEPTION 'FAIL T86c: a landed cost went onto stock not on hand';
  EXCEPTION WHEN check_violation THEN NULL; END;
  SELECT value INTO before FROM stock_balances WHERE company_id = app_company_id() AND item_id = b1 AND warehouse_id = a1;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id, amount)
    VALUES (app_company_id(), b1, a1, '2027-08-17', 'landed_cost', 0, 0, cb, 5);
  IF (SELECT value FROM stock_balances WHERE company_id = app_company_id() AND item_id = b1 AND warehouse_id = a1) <> before + 5 THEN
    RAISE EXCEPTION 'FAIL T86d: a landed cost did not raise the stock value';
  END IF;

  -- The record: two posted bills, an entry of its own, no further than the cost bill before tax, kept.
  BEGIN
    INSERT INTO landed_costs (company_id, cost_bill_id, goods_bill_id, amount, method, applied_on, journal_entry_id, created_by)
      VALUES (app_company_id(), cb, d9, 200, 'value', '2027-08-17', e1, app_user_id());
    RAISE EXCEPTION 'FAIL T86e: a landed cost was posted in a bill''s entry';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO landed_costs (company_id, cost_bill_id, goods_bill_id, amount, method, applied_on, journal_entry_id, created_by)
    VALUES (app_company_id(), cb, d9, 200, 'value', '2027-08-17', e2, app_user_id());
  BEGIN
    INSERT INTO landed_costs (company_id, cost_bill_id, goods_bill_id, amount, method, applied_on, journal_entry_id, created_by)
      VALUES (app_company_id(), cb, d9, 150, 'quantity', '2027-08-17', e2, app_user_id());
    RAISE EXCEPTION 'FAIL T86f: a cost bill was spread beyond its amount';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO landed_costs (company_id, cost_bill_id, goods_bill_id, amount, method, applied_on, journal_entry_id, created_by)
      VALUES (app_company_id(), cb, cb, 50, 'value', '2027-08-17', e2, app_user_id());
    RAISE EXCEPTION 'FAIL T86g: a bill was spread onto itself';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE landed_costs SET amount = 1 WHERE cost_bill_id = cb;
    RAISE EXCEPTION 'FAIL T86h: a landed cost was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM landed_costs) THEN
    RAISE EXCEPTION 'FAIL T86i: company B saw company A''s landed costs';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T86 landed costs add value onto stock on hand, join two posted bills, stay within the cost bill and are kept';
END $$;
-- T87: a fixed asset's tax depreciation (061). Straight line names its months, a reducing balance its rate, never both;
-- what tax allowed before Ledgerly is never more than the cost; no tax method, no tax figures. Company A as f1, the
-- accounts of T69.
DO $$
DECLARE
  a constant uuid := '00000000-0000-0000-0000-0000000087f1';
BEGIN
  INSERT INTO fixed_assets (id, company_id, code, name, asset_account_id, depreciation_account_id, expense_account_id, acquired_on, cost, method,
                            useful_life_months, depreciation_starts_on, created_by) VALUES
    (a, app_company_id(), 'VAN-87', 'Tax van', '00000000-0000-0000-0000-0000000069a1', '00000000-0000-0000-0000-0000000069a2',
     '00000000-0000-0000-0000-0000000069a3', '2027-07-15', 60000, 'straight_line', 60, '2027-08-01', '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    UPDATE fixed_assets SET tax_method = 'straight_line' WHERE id = a;
    RAISE EXCEPTION 'FAIL T87a: a straight-line tax method had no number of months';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fixed_assets SET tax_method = 'reducing_balance', tax_annual_rate = 0.25, tax_useful_life_months = 48 WHERE id = a;
    RAISE EXCEPTION 'FAIL T87b: a reducing-balance tax method also named months';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fixed_assets SET tax_opening_depreciation = 100 WHERE id = a;
    RAISE EXCEPTION 'FAIL T87c: tax depreciation before Ledgerly without a tax method';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE fixed_assets SET tax_method = 'reducing_balance', tax_annual_rate = 0.25, tax_opening_depreciation = 60001 WHERE id = a;
    RAISE EXCEPTION 'FAIL T87d: tax depreciation before Ledgerly was more than the cost';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE fixed_assets SET tax_method = 'reducing_balance', tax_annual_rate = 0.25, tax_opening_depreciation = 1000 WHERE id = a;
  RAISE NOTICE 'PASS T87 an asset''s tax depreciation names one rule, and never more than its cost before Ledgerly';
END $$;
-- T88: post-dated cheques (062). Held from the company's own customer (in) or to its own supplier (out) through its
-- own bank account, dated after the day it changes hands; a cheque written is numbered once per account; it stays as
-- recorded, is presented once as the matching payment or cancelled, and another company never sees it. Company A as
-- f1: customer c1, supplier c8, bank account a10.
DO $$
DECLARE
  c1  constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c8  constant uuid := '00000000-0000-0000-0000-0000000000c8';
  a10 constant uuid := '00000000-0000-0000-0000-000000000a10';
  p   constant uuid := '00000000-0000-0000-0000-000000000c88';
  pay constant uuid := '00000000-0000-0000-0000-000000000e88';
BEGIN
  BEGIN
    INSERT INTO post_dated_cheques (company_id, direction, contact_id, account_id, cheque_number, cheque_date, handed_on, currency, amount, created_by)
      VALUES (app_company_id(), 'in', c8, a10, '880001', '2027-09-30', '2027-08-20', 'ETB', 500, app_user_id());
    RAISE EXCEPTION 'FAIL T88a: a cheque was received from a supplier who is not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO post_dated_cheques (company_id, direction, contact_id, account_id, cheque_number, cheque_date, handed_on, currency, amount, created_by)
      VALUES (app_company_id(), 'in', c1, a10, '880001', '2027-08-20', '2027-08-20', 'ETB', 500, app_user_id());
    RAISE EXCEPTION 'FAIL T88b: a cheque dated the day it changed hands was held as post-dated';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO post_dated_cheques (id, company_id, direction, contact_id, account_id, cheque_number, cheque_date, handed_on, currency, amount, created_by)
    VALUES (p, app_company_id(), 'in', c1, a10, '880001', '2027-09-30', '2027-08-20', 'ETB', 500, app_user_id());
  INSERT INTO post_dated_cheques (company_id, direction, contact_id, account_id, cheque_number, cheque_date, handed_on, currency, amount, created_by)
    VALUES (app_company_id(), 'out', c8, a10, '990001', '2027-09-30', '2027-08-20', 'ETB', 300, app_user_id());
  BEGIN
    INSERT INTO post_dated_cheques (company_id, direction, contact_id, account_id, cheque_number, cheque_date, handed_on, currency, amount, created_by)
      VALUES (app_company_id(), 'out', c8, a10, '990001', '2027-10-31', '2027-08-21', 'ETB', 200, app_user_id());
    RAISE EXCEPTION 'FAIL T88c: a cheque number of the account was written twice';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    UPDATE post_dated_cheques SET amount = 600 WHERE id = p;
    RAISE EXCEPTION 'FAIL T88d: a post-dated cheque was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- Presented only as the payment it became: same customer, account, number and amount, on or after its date.
  INSERT INTO payments (id, company_id, direction, contact_id, account_id, payment_date, method, currency, amount, cheque_number)
    VALUES (pay, app_company_id(), 'in', c1, a10, '2027-09-30', 'cheque', 'ETB', 400, '880001');
  BEGIN
    UPDATE post_dated_cheques SET status = 'presented', payment_id = pay, closed_on = '2027-09-30' WHERE id = p;
    RAISE EXCEPTION 'FAIL T88e: a cheque was presented as a payment of another amount';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE post_dated_cheques SET status = 'cancelled', closed_on = '2027-08-25' WHERE id = p;
  BEGIN
    UPDATE post_dated_cheques SET status = 'held', closed_on = NULL WHERE id = p;
    RAISE EXCEPTION 'FAIL T88f: a cancelled cheque was held again';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM post_dated_cheques WHERE id = p;
    RAISE EXCEPTION 'FAIL T88g: a post-dated cheque was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM post_dated_cheques) THEN
    RAISE EXCEPTION 'FAIL T88h: company B saw company A''s post-dated cheques';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T88 post-dated cheques are held apart from the books, numbered once, and presented once as their payment';
END $$;
-- T89: files attached to documents (063). One of the company's own documents, a PDF, PNG, JPEG or WebP of at most
-- 10 MB under a random key, at most 20 a document; never changed or deleted, removed once; another company never sees
-- them. Company A as f1, invoice d1 (T9).
DO $$
DECLARE
  d1 constant uuid := '00000000-0000-0000-0000-0000000000d1';
  a  constant uuid := '00000000-0000-0000-0000-000000000a89';
  i  integer;
BEGIN
  BEGIN
    INSERT INTO attachments (company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), d1, 'run.html', 'text/html', 10, repeat('a', 64), repeat('b', 40), app_user_id());
    RAISE EXCEPTION 'FAIL T89a: an HTML file was kept';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO attachments (company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), d1, 'big.pdf', 'application/pdf', 10485761, repeat('a', 64), repeat('b', 40), app_user_id());
    RAISE EXCEPTION 'FAIL T89b: a file over 10 MB was kept';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO attachments (company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), d1, 'x.pdf', 'application/pdf', 10, repeat('a', 64), '../../etc/passwd', app_user_id());
    RAISE EXCEPTION 'FAIL T89c: a file was stored under a path of its own';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO attachments (id, company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
    VALUES (a, app_company_id(), d1, 'receipt.pdf', 'application/pdf', 1200, repeat('a', 64), repeat('c', 40), app_user_id());
  FOR i IN 1..19 LOOP
    INSERT INTO attachments (company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), d1, 'page-' || i || '.png', 'image/png', 100, repeat('a', 64), lpad(i::text, 40, 'd'), app_user_id());
  END LOOP;
  BEGIN
    INSERT INTO attachments (company_id, document_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), d1, 'page-21.png', 'image/png', 100, repeat('a', 64), repeat('e', 40), app_user_id());
    RAISE EXCEPTION 'FAIL T89d: a document kept a 21st attachment';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE attachments SET file_name = 'other.pdf' WHERE id = a;
    RAISE EXCEPTION 'FAIL T89e: an attachment was renamed';
  EXCEPTION WHEN insufficient_privilege OR check_violation THEN NULL; END;
  UPDATE attachments SET removed_at = now(), removed_by = app_user_id() WHERE id = a;
  BEGIN
    UPDATE attachments SET removed_at = now() + interval '1 day' WHERE id = a;
    RAISE EXCEPTION 'FAIL T89f: an attachment was removed twice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM attachments WHERE id = a;
    RAISE EXCEPTION 'FAIL T89g: an attachment was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM attachments) THEN
    RAISE EXCEPTION 'FAIL T89h: company B saw company A''s attachments';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T89 attachments are the company''s own kinds of file, at most 20 a document, never changed and removed once';
END $$;
-- T90: FIFO (064). A FIFO item's moves out take the oldest layers first at their own cost (not the average), a landed
-- cost goes onto the layers on hand, it never goes below zero, its costing is fixed once it has moved, only a stock
-- item is FIFO, layers are written only by the valuation and seen only by their company. Company A as f1, warehouse a1.
DO $$
DECLARE
  a1  constant uuid := '00000000-0000-0000-0000-0000000000a1';
  f   constant uuid := '00000000-0000-0000-0000-000000000b90';
  amt numeric;
BEGIN
  BEGIN
    INSERT INTO items (company_id, name, type, costing) VALUES (app_company_id(), 'Delivery', 'service', 'fifo');
    RAISE EXCEPTION 'FAIL T90a: a service was costed FIFO';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO items (id, company_id, name, type, costing) VALUES (f, app_company_id(), 'Green beans (FIFO)', 'inventory', 'fifo');
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), f, a1, '2027-08-01', 'purchase', 10, 10);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), f, a1, '2027-08-02', 'purchase', 10, 20);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), f, a1, '2027-08-03', 'sale', -15, 0)
    RETURNING amount INTO amt;
  IF amt <> -200 THEN   -- 10 x 10.00 + 5 x 20.00; the average would have been 225.00
    RAISE EXCEPTION 'FAIL T90b: 15 FIFO units left at %, not 200.00 from the oldest layers', -amt;
  END IF;
  IF (SELECT value FROM stock_balances WHERE company_id = app_company_id() AND item_id = f AND warehouse_id = a1) <> 100
     OR (SELECT sum(value_remaining) FROM stock_layers WHERE company_id = app_company_id() AND item_id = f) <> 100 THEN
    RAISE EXCEPTION 'FAIL T90c: the balance and the layers do not hold the 100.00 left';
  END IF;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, amount) VALUES (app_company_id(), f, a1, '2027-08-04', 'landed_cost', 0, 0, 10);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), f, a1, '2027-08-05', 'sale', -5, 0)
    RETURNING amount INTO amt;
  IF amt <> -110 THEN
    RAISE EXCEPTION 'FAIL T90d: the last 5 left at %, not 110.00 with the landed cost', -amt;
  END IF;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), f, a1, '2027-08-06', 'sale', -1, 0);
    RAISE EXCEPTION 'FAIL T90e: a FIFO item went below zero';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE items SET costing = 'average' WHERE id = f;
    RAISE EXCEPTION 'FAIL T90f: the costing of an item with moves changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_layers (company_id, item_id, warehouse_id, move_id, received_on, quantity, remaining, value_remaining)
      VALUES (app_company_id(), f, a1, gen_random_uuid(), '2027-08-06', 1, 1, 0);
    RAISE EXCEPTION 'FAIL T90g: the application wrote a cost layer';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM stock_layers) THEN
    RAISE EXCEPTION 'FAIL T90h: company B saw company A''s cost layers';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T90 FIFO items leave from their oldest layers, take landed costs onto what is on hand, never go below zero, and keep their costing';
END $$;
-- T91: quote links (065). Made only for a sent quote of the company, for up to 90 days, never written by the
-- application directly; looked up and answered only outside a company, once; another company never sees them.
-- Company A as f1: the converted quote e82 and the sent quote e85 (T82).
DO $$
DECLARE
  converted constant uuid := '00000000-0000-0000-0000-000000000e82';
  sent      constant uuid := '00000000-0000-0000-0000-000000000e85';
  h         constant text := repeat('ab', 32);
  got       record;
BEGIN
  BEGIN
    PERFORM quote_link_issue(converted, h, now() + interval '10 days');
    RAISE EXCEPTION 'FAIL T91a: a link was made for a quote that is not waiting for an answer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    PERFORM quote_link_issue(sent, h, now() + interval '91 days');
    RAISE EXCEPTION 'FAIL T91b: a link lasted more than 90 days';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO quote_links (company_id, document_id, token_hash, expires_at, created_by) VALUES (app_company_id(), sent, h, now() + interval '1 day', app_user_id());
    RAISE EXCEPTION 'FAIL T91c: the application wrote a quote link itself';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  PERFORM quote_link_issue(sent, h, now() + interval '10 days');
  IF EXISTS (SELECT 1 FROM quote_link_lookup(h)) THEN
    RAISE EXCEPTION 'FAIL T91d: a quote link was looked up inside a company';
  END IF;

  PERFORM set_config('app.company_id', '', true), set_config('app.user_id', '', true);
  SELECT * INTO got FROM quote_link_lookup(h);
  IF got.document_id IS DISTINCT FROM sent THEN
    RAISE EXCEPTION 'FAIL T91e: the link did not lead to its quote';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM quote_link_answer(h, 'accepted', 'Abebe Kebede')) OR EXISTS (SELECT 1 FROM quote_link_answer(h, 'declined', 'Someone else')) THEN
    RAISE EXCEPTION 'FAIL T91f: a link was not answered exactly once';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM quote_links) THEN
    RAISE EXCEPTION 'FAIL T91g: company B saw company A''s quote links';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T91 quote links are made for sent quotes only, answered once outside any company, and seen only by their own';
END $$;
-- T92: the staff console (066). The application never reads or makes staff accounts, tokens or sessions directly;
-- a staff token opens the company list and a support session only while valid; a session needs a reason and lasts at
-- most two hours; a code works once; a setup link sets a bcrypt password once; the staff log is append-only.
DO $$ BEGIN
  BEGIN
    PERFORM 1 FROM staff_users;
    RAISE EXCEPTION 'FAIL T92a: the application read staff accounts';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    INSERT INTO staff_users (email, name) VALUES ('mallory@example.test', 'Mallory');
    RAISE EXCEPTION 'FAIL T92b: the application made a staff account';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;
RESET ROLE;
INSERT INTO staff_users (id, email, name, password, mfa_secret) VALUES
  ('00000000-0000-0000-0000-0000000005f1', 'support@ledgerly.example', 'Support One', '$2y$12$abcdefghijklmnopqrstuuNw2oSj1lB8rM7i3e6t4mY2wQyQ1rS2u', repeat('x', 40));
INSERT INTO staff_users (id, email, name, setup_hash, setup_expires_at) VALUES
  ('00000000-0000-0000-0000-0000000005f2', 'new.staff@ledgerly.example', 'New Staff', repeat('5e', 32), now() + interval '1 day');
SET ROLE ledgerly_web;
SELECT set_config('app.user_id', '', false), set_config('app.company_id', '', false) \gset ignore_
DO $$
DECLARE
  s1  constant uuid := '00000000-0000-0000-0000-0000000005f1';
  tok constant text := repeat('7a', 32);
  ses uuid;
BEGIN
  IF EXISTS (SELECT 1 FROM staff_companies(tok, '')) THEN
    RAISE EXCEPTION 'FAIL T92c: the company list opened without a staff token';
  END IF;
  PERFORM staff_sign_in_complete(s1, 1000, tok, 8, '192.0.2.10');
  BEGIN
    PERFORM staff_sign_in_complete(s1, 1000, repeat('7b', 32), 1, '192.0.2.10');
    RAISE EXCEPTION 'FAIL T92d: an authenticator code was used twice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  IF (SELECT name FROM staff_token_check(tok)) IS DISTINCT FROM 'Support One'
     OR NOT EXISTS (SELECT 1 FROM staff_companies(tok, 'Company A')) THEN
    RAISE EXCEPTION 'FAIL T92e: a valid staff token did not open the company list';
  END IF;
  BEGIN
    PERFORM staff_session_start(tok, '00000000-0000-0000-0000-00000000000a', 'short', 30);
    RAISE EXCEPTION 'FAIL T92f: a support session started without a reason';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    PERFORM staff_session_start(tok, '00000000-0000-0000-0000-00000000000a', 'Customer asked why the trial balance is off', 500);
    RAISE EXCEPTION 'FAIL T92g: a support session lasted more than two hours';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    PERFORM staff_session_start(repeat('00', 32), '00000000-0000-0000-0000-00000000000a', 'Customer asked why the trial balance is off', 30);
    RAISE EXCEPTION 'FAIL T92h: a support session started without a valid staff token';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  ses := staff_session_start(tok, '00000000-0000-0000-0000-00000000000a', 'Customer asked why the trial balance is off', 30);
  IF (SELECT company_id FROM staff_session_check(tok, ses)) IS DISTINCT FROM '00000000-0000-0000-0000-00000000000a'
     OR EXISTS (SELECT 1 FROM staff_session_check(repeat('00', 32), ses)) THEN
    RAISE EXCEPTION 'FAIL T92i: a support session was not tied to its staff member';
  END IF;
  PERFORM staff_session_end(tok, ses);
  PERFORM staff_sign_out(tok);
  IF EXISTS (SELECT 1 FROM staff_session_check(tok, ses)) OR EXISTS (SELECT 1 FROM staff_token_check(tok)) THEN
    RAISE EXCEPTION 'FAIL T92j: an ended session or a signed-out token still worked';
  END IF;

  -- Setup: once, with a bcrypt hash and a secret.
  BEGIN
    PERFORM staff_setup_complete(repeat('5e', 32), 'plain-password', repeat('y', 40), 5);
    RAISE EXCEPTION 'FAIL T92k: a staff password was kept as given';
  EXCEPTION WHEN check_violation THEN NULL; END;
  IF staff_setup_complete(repeat('5e', 32), '$2y$12$zyxwvutsrqponmlkjihgfeuNw2oSj1lB8rM7i3e6t4mY2wQyQ1rS2u', repeat('y', 40), 5) IS NULL
     OR staff_setup_complete(repeat('5e', 32), '$2y$12$zyxwvutsrqponmlkjihgfeuNw2oSj1lB8rM7i3e6t4mY2wQyQ1rS2u', repeat('y', 40), 6) IS NOT NULL THEN
    RAISE EXCEPTION 'FAIL T92l: a setup link did not work exactly once';
  END IF;

  INSERT INTO staff_log (staff_id, action, detail) VALUES (s1, 'support_session_started', '{"company":"A"}');
  BEGIN
    UPDATE staff_log SET action = 'nothing_happened';
    RAISE EXCEPTION 'FAIL T92m: the staff log was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T92 staff accounts are the owner''s, tokens and support sessions are checked by the database, and the staff log is kept';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', false) \gset ignore_

-- T93: sales orders delivered in parts (067). An order makes several invoices; each invoice line names a line of the
-- order its invoice was made from, the same item, a positive quantity, and the live invoices never deliver more than
-- was ordered (a void invoice gives its quantity back); an order partly delivered is closed, not cancelled, and once
-- converted makes nothing more. Company A as its owner f1, customer c1, item b1, sales account a40.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  so constant uuid := '00000000-0000-0000-0000-000000000e93';
  l1 constant uuid := '00000000-0000-0000-0000-000000000e94';
  l2 constant uuid := '00000000-0000-0000-0000-000000000e95';
  i1 constant uuid := '00000000-0000-0000-0000-000000000e96';
  i2 constant uuid := '00000000-0000-0000-0000-000000000e97';
  i3 constant uuid := '00000000-0000-0000-0000-000000000e98';
  b1 constant uuid := '00000000-0000-0000-0000-0000000000b1';
  a40 constant uuid := '00000000-0000-0000-0000-000000000a40';
  line_id uuid;
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES (so, app_company_id(), 'sales_order', c1, '2027-09-01', 'ETB');
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (l1, app_company_id(), so, 1, 'Goods', 5, 10, a40, 50, b1),
    (l2, app_company_id(), so, 2, 'Fitting', 2, 100, a40, 200, NULL);
  UPDATE documents SET status = 'accepted', number = 'SO-T93-1' WHERE id = so;

  -- Two invoices from one order, the second delivering the rest.
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id) VALUES
    (i1, app_company_id(), 'invoice', c1, '2027-09-02', 'ETB', so),
    (i2, app_company_id(), 'invoice', c1, '2027-09-03', 'ETB', so),
    (i3, app_company_id(), 'invoice', c1, '2027-09-03', 'ETB', NULL);
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), i1, 1, 'Goods', 3, 10, a40, 30, b1, l1);
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), i2, 1, 'Goods', 3, 10, a40, 30, b1, l1);
    RAISE EXCEPTION 'FAIL T93a: more was delivered than was ordered';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), i2, 1, 'Goods', 1, 10, a40, 10, NULL, l1);
    RAISE EXCEPTION 'FAIL T93b: an invoice line delivered another item than its order line';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), i3, 1, 'Fitting', 1, 100, a40, 100, NULL, l2);
    RAISE EXCEPTION 'FAIL T93c: an invoice not made from the order delivered its line';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), i2, 1, 'Fitting', -1, 100, a40, -100, NULL, l2);
    RAISE EXCEPTION 'FAIL T93d: a negative quantity was delivered';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), i2, 1, 'Line of an invoice', 1, 10, a40, 10, b1, (SELECT id FROM document_lines WHERE document_id = i1));
    RAISE EXCEPTION 'FAIL T93e: an invoice line named a line that is not an order''s';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), i2, 1, 'Goods', 2, 10, a40, 20, b1, l1)
    RETURNING id INTO line_id;
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), i2, 2, 'Fitting', 2, 100, a40, 200, NULL, l2);
  BEGIN
    UPDATE document_lines SET quantity = 3 WHERE id = line_id;
    RAISE EXCEPTION 'FAIL T93f: a line was raised past what was ordered';
  EXCEPTION WHEN check_violation THEN NULL; END;

  BEGIN
    UPDATE documents SET status = 'void' WHERE id = so;
    RAISE EXCEPTION 'FAIL T93g: an order partly delivered was cancelled';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- A void invoice gives its quantity back.
  UPDATE documents SET status = 'void' WHERE id = i1;
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), i2, 3, 'Goods', 3, 10, a40, 30, b1, l1);

  UPDATE documents SET status = 'converted' WHERE id = so;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id) VALUES
      (app_company_id(), 'invoice', c1, '2027-09-04', 'ETB', so);
    RAISE EXCEPTION 'FAIL T93h: a closed order made another invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM document_lines WHERE id IN (l1, l2) OR source_line_id IN (l1, l2)) THEN
    RAISE EXCEPTION 'FAIL T93i: company B saw company A''s order lines';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T93 a sales order is delivered in parts, never more than was ordered, and is closed rather than cancelled';
END $$;
-- T94: purchase orders (068). A purchase order owes nothing; bills made from it are for its supplier, while it is
-- issued, and bill its lines in parts, never more than was ordered; an invoice is never made from it; partly billed
-- it is closed, not cancelled; another company never sees it. Company A as its owner f1, supplier c8, item b1.
DO $$
DECLARE
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  po constant uuid := '00000000-0000-0000-0000-000000000e99';
  pl constant uuid := '00000000-0000-0000-0000-000000000e9a';
  b1 constant uuid := '00000000-0000-0000-0000-000000000e9b';
  b2 constant uuid := '00000000-0000-0000-0000-000000000e9c';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total) VALUES (po, app_company_id(), 'purchase_order', c8, '2027-09-01', 'ETB', 500);
  BEGIN
    UPDATE documents SET amount_due = 500 WHERE id = po;
    RAISE EXCEPTION 'FAIL T94a: a purchase order was owed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (pl, app_company_id(), po, 1, 'Beans', 10, 50, acc, 500, item);
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id) VALUES (app_company_id(), 'bill', c8, '2027-09-02', 'ETB', po);
    RAISE EXCEPTION 'FAIL T94b: a bill was made from a purchase order not yet issued';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'accepted', number = 'PO-T94-1' WHERE id = po;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, source_document_id) VALUES (app_company_id(), 'invoice', c1, '2027-09-02', 'ETB', po);
    RAISE EXCEPTION 'FAIL T94c: an invoice was made from a purchase order';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id) VALUES
    (b1, app_company_id(), 'bill', c8, '2027-09-02', 'ETB', po),
    (b2, app_company_id(), 'bill', c8, '2027-09-03', 'ETB', po);
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), b1, 1, 'Beans', 6, 50, acc, 300, item, pl);
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), b2, 1, 'Beans', 5, 50, acc, 250, item, pl);
    RAISE EXCEPTION 'FAIL T94d: more was billed than was ordered';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (app_company_id(), b2, 1, 'Beans', 4, 50, acc, 200, item, pl);
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = po;
    RAISE EXCEPTION 'FAIL T94e: a purchase order partly billed was cancelled';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'converted' WHERE id = po;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM documents WHERE id IN (po, b1, b2)) OR EXISTS (SELECT 1 FROM document_lines WHERE id = pl) THEN
    RAISE EXCEPTION 'FAIL T94f: company B saw company A''s purchase order';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T94 a purchase order owes nothing, is billed in parts by its supplier, never more than was ordered, and is closed rather than cancelled';
END $$;

-- T95: goods received notes (069). Goods are received against an issued purchase order only, never more than was
-- ordered; the order's bills match no more than was received; an item received is not brought in again by a bill;
-- a note whose goods are billed is not voided, and its lines and matches are kept; an order with goods received is
-- not cancelled; a price adjustment changes value only and never below zero; another company sees none of it.
-- Company A as its owner f1, supplier c8, item b1 in warehouse a1.
DO $$
DECLARE
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  po constant uuid := '00000000-0000-0000-0000-000000000ea0';
  pl constant uuid := '00000000-0000-0000-0000-000000000ea1';
  b1 constant uuid := '00000000-0000-0000-0000-000000000ea2';
  bl constant uuid := '00000000-0000-0000-0000-000000000ea3';
  r1 constant uuid := '00000000-0000-0000-0000-000000000ea4';
  b2 constant uuid := '00000000-0000-0000-0000-000000000ea5';
  bl2 constant uuid := '00000000-0000-0000-0000-000000000ea6';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  wh constant uuid := '00000000-0000-0000-0000-0000000000a1';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total) VALUES (po, app_company_id(), 'purchase_order', c8, '2027-10-01', 'ETB', 500);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (pl, app_company_id(), po, 1, 'Beans', 10, 50, acc, 500, item);
  BEGIN
    INSERT INTO goods_receipts (company_id, purchase_order_id, number, received_on, warehouse_id, created_by) VALUES (app_company_id(), po, 'GRN-T95-0', '2027-10-02', wh, f1);
    RAISE EXCEPTION 'FAIL T95a: goods were received against a purchase order not yet issued';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'accepted', number = 'PO-T95-1' WHERE id = po;

  INSERT INTO goods_receipts (id, company_id, purchase_order_id, number, received_on, warehouse_id, created_by) VALUES (r1, app_company_id(), po, 'GRN-T95-1', '2027-10-02', wh, f1);
  INSERT INTO goods_receipt_lines (company_id, goods_receipt_id, order_line_id, item_id, quantity, amount) VALUES (app_company_id(), r1, pl, item, 6, 300);
  BEGIN
    INSERT INTO goods_receipt_lines (company_id, goods_receipt_id, order_line_id, item_id, quantity, amount) VALUES (app_company_id(), r1, pl, item, 5, 250);
    RAISE EXCEPTION 'FAIL T95b: more was received than was ordered';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id) VALUES
    (b1, app_company_id(), 'bill', c8, '2027-10-03', 'ETB', po), (b2, app_company_id(), 'bill', c8, '2027-10-04', 'ETB', po);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (bl, app_company_id(), b1, 1, 'Beans', 6, 50, acc, 300, item, pl), (bl2, app_company_id(), b2, 1, 'Beans', 1, 50, acc, 50, item, pl);
  INSERT INTO bill_receipt_matches (company_id, bill_id, bill_line_id, order_line_id, quantity, amount) VALUES (app_company_id(), b1, bl, pl, 6, 300);
  BEGIN
    INSERT INTO bill_receipt_matches (company_id, bill_id, bill_line_id, order_line_id, quantity, amount) VALUES (app_company_id(), b2, bl2, pl, 1, 50);
    RAISE EXCEPTION 'FAIL T95c: more was billed than was received';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES (app_company_id(), item, wh, '2027-10-03', 'purchase', 6, 50, b1);
    RAISE EXCEPTION 'FAIL T95d: a bill brought in goods its order had received';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE goods_receipts SET status = 'void', voided_at = now() WHERE id = r1;
    RAISE EXCEPTION 'FAIL T95e: a goods received note whose goods are billed was voided';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE goods_receipts SET received_on = '2027-10-05' WHERE id = r1;
    RAISE EXCEPTION 'FAIL T95f: a goods received note was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE goods_receipt_lines SET quantity = 1 WHERE goods_receipt_id = r1;
    RAISE EXCEPTION 'FAIL T95g: a goods received note''s line was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM bill_receipt_matches WHERE bill_id = b1;
    RAISE EXCEPTION 'FAIL T95h: a bill''s match to goods received was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = po;
    RAISE EXCEPTION 'FAIL T95i: a purchase order with goods received was cancelled';
  EXCEPTION WHEN check_violation THEN NULL; END;

  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, amount) VALUES (app_company_id(), item, wh, '2027-10-03', 'price_adjustment', 0, 0, -999999999);
    RAISE EXCEPTION 'FAIL T95j: a price adjustment took the stock''s value below zero';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, amount) VALUES (app_company_id(), item, wh, '2027-10-03', 'price_adjustment', 1, 0, 10);
    RAISE EXCEPTION 'FAIL T95k: a price adjustment moved a quantity';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, amount) VALUES (app_company_id(), item, wh, '2027-10-03', 'price_adjustment', 0, 0, -10);

  -- The bill voided gives its quantity back: the note can then be voided.
  UPDATE documents SET status = 'void' WHERE id = b1;
  UPDATE goods_receipts SET status = 'void', voided_at = now(), voided_by = f1, void_reason = 'T95' WHERE id = r1;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM goods_receipts WHERE id = r1) OR EXISTS (SELECT 1 FROM goods_receipt_lines WHERE goods_receipt_id = r1)
     OR EXISTS (SELECT 1 FROM bill_receipt_matches WHERE bill_id = b1) THEN
    RAISE EXCEPTION 'FAIL T95l: company B saw company A''s goods received';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T95 goods are received against an issued order, never beyond it, billed no further than received, kept, and value-only price adjustments stay above zero';
END $$;

-- T96: sales receipts (070). A cash sale names the money account it went into and how it was paid, one of the
-- company's own bank, cash or mobile-money accounts; posted, it leaves nothing to pay; it is for a customer; an
-- invoice names no money account; another company never sees it. Company A as f1, customer c1, petty cash a57.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  cash constant uuid := '00000000-0000-0000-0000-000000000a57';
  income constant uuid := '00000000-0000-0000-0000-000000000a40';
  sr constant uuid := '00000000-0000-0000-0000-000000000eb0';
BEGIN
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency) VALUES (app_company_id(), 'sales_receipt', c1, '2027-10-01', 'ETB');
    RAISE EXCEPTION 'FAIL T96a: a sales receipt named no money account';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'sales_receipt', c1, '2027-10-01', 'ETB', income, 'cash');
    RAISE EXCEPTION 'FAIL T96b: a sales receipt went into an account that holds no money';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'sales_receipt', c8, '2027-10-01', 'ETB', cash, 'cash');
    RAISE EXCEPTION 'FAIL T96c: a sales receipt was made for a contact that is not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method)
      VALUES (app_company_id(), 'invoice', c1, '2027-10-01', 'ETB', cash, 'cash');
    RAISE EXCEPTION 'FAIL T96d: an invoice named a money account';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, paid_from_account_id, payment_method, total, amount_due)
    VALUES (sr, app_company_id(), 'sales_receipt', c1, '2027-10-01', 'ETB', cash, 'mobile_money', 115, 115);
  BEGIN
    UPDATE documents SET status = 'posted', number = 'SR-T96-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = sr;
    RAISE EXCEPTION 'FAIL T96e: a posted sales receipt was left owing';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'posted', number = 'SR-T96-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001', amount_due = 0 WHERE id = sr;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM documents WHERE id = sr) THEN
    RAISE EXCEPTION 'FAIL T96f: company B saw company A''s sales receipt';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T96 a sales receipt names the money account it went into, is for a customer and leaves nothing to pay';
END $$;

-- T97: delivery notes (071). Goods are delivered against a confirmed sales order only, never more than was ordered;
-- the order's invoices match no more than was delivered; an item delivered is not taken out again by an invoice; a note
-- whose goods are invoiced is not voided, and its lines and matches are kept; an order with goods delivered is not
-- cancelled; another company sees none of it. Company A as f1, customer c1, item b1 in warehouse a1.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  so constant uuid := '00000000-0000-0000-0000-000000000ec0';
  sl constant uuid := '00000000-0000-0000-0000-000000000ec1';
  i1 constant uuid := '00000000-0000-0000-0000-000000000ec2';
  il constant uuid := '00000000-0000-0000-0000-000000000ec3';
  d1 constant uuid := '00000000-0000-0000-0000-000000000ec4';
  i2 constant uuid := '00000000-0000-0000-0000-000000000ec5';
  il2 constant uuid := '00000000-0000-0000-0000-000000000ec6';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  wh constant uuid := '00000000-0000-0000-0000-0000000000a1';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total) VALUES (so, app_company_id(), 'sales_order', c1, '2027-10-01', 'ETB', 1000);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (sl, app_company_id(), so, 1, 'Coffee', 10, 100, acc, 1000, item);
  BEGIN
    INSERT INTO delivery_notes (company_id, sales_order_id, number, delivered_on, warehouse_id, created_by) VALUES (app_company_id(), so, 'DN-T97-0', '2027-10-02', wh, f1);
    RAISE EXCEPTION 'FAIL T97a: goods were delivered against a sales order not yet confirmed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'accepted', number = 'SO-T97-1' WHERE id = so;

  INSERT INTO delivery_notes (id, company_id, sales_order_id, number, delivered_on, warehouse_id, created_by) VALUES (d1, app_company_id(), so, 'DN-T97-1', '2027-10-02', wh, f1);
  INSERT INTO delivery_note_lines (company_id, delivery_note_id, order_line_id, item_id, quantity) VALUES (app_company_id(), d1, sl, item, 6);
  BEGIN
    INSERT INTO delivery_note_lines (company_id, delivery_note_id, order_line_id, item_id, quantity) VALUES (app_company_id(), d1, sl, item, 5);
    RAISE EXCEPTION 'FAIL T97b: more was delivered than was ordered';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id) VALUES
    (i1, app_company_id(), 'invoice', c1, '2027-10-03', 'ETB', so), (i2, app_company_id(), 'invoice', c1, '2027-10-04', 'ETB', so);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (il, app_company_id(), i1, 1, 'Coffee', 6, 100, acc, 600, item, sl), (il2, app_company_id(), i2, 1, 'Coffee', 1, 100, acc, 100, item, sl);
  INSERT INTO invoice_delivery_matches (company_id, invoice_id, invoice_line_id, order_line_id, quantity) VALUES (app_company_id(), i1, il, sl, 6);
  BEGIN
    INSERT INTO invoice_delivery_matches (company_id, invoice_id, invoice_line_id, order_line_id, quantity) VALUES (app_company_id(), i2, il2, sl, 1);
    RAISE EXCEPTION 'FAIL T97c: more was invoiced than was delivered';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES (app_company_id(), item, wh, '2027-10-03', 'sale', -6, 0, i1);
    RAISE EXCEPTION 'FAIL T97d: an invoice took out goods its order had delivered';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE delivery_notes SET status = 'void', voided_at = now() WHERE id = d1;
    RAISE EXCEPTION 'FAIL T97e: a delivery note whose goods are invoiced was voided';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE delivery_notes SET delivered_on = '2027-10-05' WHERE id = d1;
    RAISE EXCEPTION 'FAIL T97f: a delivery note was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE delivery_note_lines SET quantity = 1 WHERE delivery_note_id = d1;
    RAISE EXCEPTION 'FAIL T97g: a delivery note''s line was changed';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM invoice_delivery_matches WHERE invoice_id = i1;
    RAISE EXCEPTION 'FAIL T97h: an invoice''s match to goods delivered was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE documents SET status = 'void' WHERE id = so;
    RAISE EXCEPTION 'FAIL T97i: a sales order with goods delivered was cancelled';
  EXCEPTION WHEN check_violation THEN NULL; END;

  -- The invoice voided gives its quantity back: the note can then be voided.
  UPDATE documents SET status = 'void' WHERE id = i1;
  UPDATE delivery_notes SET status = 'void', voided_at = now(), voided_by = f1, void_reason = 'T97' WHERE id = d1;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM delivery_notes WHERE id = d1) OR EXISTS (SELECT 1 FROM delivery_note_lines WHERE delivery_note_id = d1)
     OR EXISTS (SELECT 1 FROM invoice_delivery_matches WHERE invoice_id = i1) THEN
    RAISE EXCEPTION 'FAIL T97j: company B saw company A''s deliveries';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T97 goods are delivered against a confirmed order, never beyond it, invoiced no further than delivered, and kept';
END $$;

-- T98: units of measure (072). A unit is one of the company's items' own, never the item's own unit, of a size above
-- zero that never changes; a document line in a unit takes its name and size as they are then, whatever it was given,
-- only for its own item and only while the unit is active; a line made from an order line is in its unit; a unit is
-- never deleted; another company never sees one. Company A as f1, customer c1, item b1 (counted 'each').
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  other constant uuid := '00000000-0000-0000-0000-000000000ed0';
  bag constant uuid := '00000000-0000-0000-0000-000000000ed1';
  so constant uuid := '00000000-0000-0000-0000-000000000ed2';
  sl constant uuid := '00000000-0000-0000-0000-000000000ed3';
  inv constant uuid := '00000000-0000-0000-0000-000000000ed4';
  il constant uuid := '00000000-0000-0000-0000-000000000ed5';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
  f numeric;
  n text;
BEGIN
  INSERT INTO items (id, company_id, name, type) VALUES (other, app_company_id(), 'T98 cups', 'service');
  BEGIN
    INSERT INTO item_units (company_id, item_id, name, factor) VALUES (app_company_id(), item, ' Each ', 12);
    RAISE EXCEPTION 'FAIL T98a: a unit took the item''s own unit';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO item_units (company_id, item_id, name, factor) VALUES (app_company_id(), item, 'Box', 0);
    RAISE EXCEPTION 'FAIL T98b: a unit of size zero was made';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO item_units (id, company_id, item_id, name, factor) VALUES (bag, app_company_id(), item, 'Bag', 25);

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES (so, app_company_id(), 'sales_order', c1, '2027-11-01', 'ETB');
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, unit_id) VALUES
      (app_company_id(), so, 1, 'Cups', 1, 10, acc, 10, other, bag);
    RAISE EXCEPTION 'FAIL T98c: a line used another item''s unit';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, unit_id, unit_factor, unit_name) VALUES
    (sl, app_company_id(), so, 1, 'Coffee, bag', 4, 2500, acc, 10000, item, bag, 1, 'Spoon');
  SELECT unit_factor, unit_name INTO f, n FROM document_lines WHERE id = sl;
  IF f <> 25 OR n <> 'Bag' THEN
    RAISE EXCEPTION 'FAIL T98d: a line kept the size it was given (% %), not its unit''s', f, n;
  END IF;
  UPDATE document_lines SET unit_factor = 2 WHERE id = sl;
  IF (SELECT unit_factor FROM document_lines WHERE id = sl) <> 25 THEN
    RAISE EXCEPTION 'FAIL T98d: a line''s unit size was changed';
  END IF;

  BEGIN
    UPDATE item_units SET factor = 50 WHERE id = bag;
    RAISE EXCEPTION 'FAIL T98e: a unit''s size was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM item_units WHERE id = bag;
    RAISE EXCEPTION 'FAIL T98f: a unit was deleted';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;

  UPDATE documents SET status = 'accepted', number = 'SO-T98-1' WHERE id = so;
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id) VALUES (inv, app_company_id(), 'invoice', c1, '2027-11-02', 'ETB', so);
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
      (app_company_id(), inv, 1, 'Coffee', 4, 100, acc, 400, item, sl);
    RAISE EXCEPTION 'FAIL T98g: a line made from an order line was in another unit';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id, unit_id) VALUES
    (il, app_company_id(), inv, 1, 'Coffee, bag', 4, 2500, acc, 10000, item, sl, bag);

  -- Archived, a unit is no longer chosen; lines already in it keep it.
  UPDATE item_units SET is_active = false WHERE id = bag;
  BEGIN
    INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, unit_id) VALUES
      (app_company_id(), so, 2, 'Coffee, bag', 1, 2500, acc, 2500, item, bag);
    RAISE EXCEPTION 'FAIL T98h: an archived unit was chosen';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE document_lines SET description = 'Coffee, 25 kg bag' WHERE id = il;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM item_units WHERE id = bag) THEN
    RAISE EXCEPTION 'FAIL T98i: company B saw company A''s unit';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T98 a unit is its item''s own and keeps its size; a line takes its unit as it is and an order line''s unit carries on';
END $$;

-- T99: bundles (073). A bundle's parts are other items of the company, never a bundle, once each, above zero; they are
-- fixed once the bundle is on a document; a bundle with parts stays a bundle and holds no stock; another company
-- never sees its parts. Company A as f1, customer c1, item b1 (stock), warehouse a1.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  wh constant uuid := '00000000-0000-0000-0000-0000000000a1';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
  box constant uuid := '00000000-0000-0000-0000-000000000ee0';
  box2 constant uuid := '00000000-0000-0000-0000-000000000ee1';
  plain constant uuid := '00000000-0000-0000-0000-000000000ee2';
  inv constant uuid := '00000000-0000-0000-0000-000000000ee3';
BEGIN
  INSERT INTO items (id, company_id, name, type) VALUES (box, app_company_id(), 'T99 gift box', 'bundle'), (box2, app_company_id(), 'T99 big box', 'bundle'),
    (plain, app_company_id(), 'T99 wrapping', 'non_inventory');
  BEGIN
    INSERT INTO item_components (company_id, bundle_id, component_id, quantity) VALUES (app_company_id(), plain, item, 1);
    RAISE EXCEPTION 'FAIL T99a: an item that is not a bundle was given parts';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO item_components (company_id, bundle_id, component_id, quantity) VALUES (app_company_id(), box2, box, 1);
    RAISE EXCEPTION 'FAIL T99b: a bundle was made a part of another bundle';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO item_components (company_id, bundle_id, component_id, quantity) VALUES (app_company_id(), box, item, 0);
    RAISE EXCEPTION 'FAIL T99c: a part of quantity zero was added';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO item_components (company_id, bundle_id, component_id, quantity) VALUES (app_company_id(), box, item, 2), (app_company_id(), box, plain, 1);
  BEGIN
    UPDATE items SET type = 'inventory' WHERE id = box;
    RAISE EXCEPTION 'FAIL T99d: a bundle with parts stopped being a bundle';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), box, wh, '2027-11-01', 'purchase', 1, 10);
    RAISE EXCEPTION 'FAIL T99e: a bundle had a stock move of its own';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES (inv, app_company_id(), 'invoice', c1, '2027-11-01', 'ETB');
  INSERT INTO document_lines (company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (app_company_id(), inv, 1, 'Gift box', 1, 500, acc, 500, box);
  BEGIN
    UPDATE item_components SET quantity = 3 WHERE bundle_id = box AND component_id = item;
    RAISE EXCEPTION 'FAIL T99f: a used bundle''s parts were changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    DELETE FROM item_components WHERE bundle_id = box AND component_id = plain;
    RAISE EXCEPTION 'FAIL T99g: a used bundle lost a part';
  EXCEPTION WHEN check_violation THEN NULL; END;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM item_components WHERE bundle_id = box) THEN
    RAISE EXCEPTION 'FAIL T99h: company B saw company A''s bundle parts';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T99 a bundle''s parts are other items, fixed once it is used, and it holds no stock of its own';
END $$;

-- T100: batches (074). Tracking is chosen before an item's first stock move, for a stock item only; a batch belongs to
-- a tracked item, its number never changes and it is kept; every move of a tracked item names one of its own batches,
-- and no other move does; a batch's stock never goes below zero; another company never sees a batch. Company A as f1,
-- item b1 (untracked, with moves) in warehouse a1.
DO $$
DECLARE
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  wh constant uuid := '00000000-0000-0000-0000-0000000000a1';
  milk constant uuid := '00000000-0000-0000-0000-000000000ef0';
  ba constant uuid := '00000000-0000-0000-0000-000000000ef1';
  bb constant uuid := '00000000-0000-0000-0000-000000000ef2';
  q numeric;
BEGIN
  BEGIN
    UPDATE items SET tracking = 'batch' WHERE id = item;
    RAISE EXCEPTION 'FAIL T100a: an item with stock moves changed its tracking';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO items (company_id, name, type, tracking) VALUES (app_company_id(), 'T100 service', 'service', 'batch');
    RAISE EXCEPTION 'FAIL T100b: an item that is not stock was tracked by batch';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO items (id, company_id, name, type, tracking) VALUES (milk, app_company_id(), 'T100 milk powder', 'inventory', 'batch');
  BEGIN
    INSERT INTO stock_batches (company_id, item_id, number) VALUES (app_company_id(), item, 'X1');
    RAISE EXCEPTION 'FAIL T100c: a batch was made for an item not tracked by batch';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO stock_batches (id, company_id, item_id, number, expires_on) VALUES (ba, app_company_id(), milk, 'L-2401', '2028-01-31'), (bb, app_company_id(), milk, 'L-2402', '2028-03-31');

  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost) VALUES (app_company_id(), milk, wh, '2027-11-01', 'opening', 10, 40);
    RAISE EXCEPTION 'FAIL T100d: a tracked item moved without naming its batch';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, batch_id) VALUES (app_company_id(), item, wh, '2027-11-01', 'purchase', 1, 40, ba);
    RAISE EXCEPTION 'FAIL T100e: an untracked item''s move named a batch';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, batch_id) VALUES
    (app_company_id(), milk, wh, '2027-11-01', 'opening', 10, 40, ba), (app_company_id(), milk, wh, '2027-11-01', 'opening', 5, 40, bb);
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, batch_id) VALUES (app_company_id(), milk, wh, '2027-11-02', 'sale', -11, 0, ba);
    RAISE EXCEPTION 'FAIL T100f: a batch went below zero (there is enough of the item, not of the batch)';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, batch_id) VALUES (app_company_id(), milk, wh, '2027-11-02', 'sale', -4, 0, ba);
  SELECT quantity INTO q FROM batch_balances WHERE batch_id = ba AND warehouse_id = wh;
  IF q <> 6 THEN
    RAISE EXCEPTION 'FAIL T100g: batch stock is % after 10 in and 4 out', q;
  END IF;
  BEGIN
    UPDATE stock_batches SET number = 'L-9999' WHERE id = ba;
    RAISE EXCEPTION 'FAIL T100h: a batch''s number was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE batch_balances SET quantity = 100 WHERE batch_id = ba;
    RAISE EXCEPTION 'FAIL T100i: the application wrote a batch''s stock';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    UPDATE items SET tracking = 'none' WHERE id = milk;
    RAISE EXCEPTION 'FAIL T100j: a tracked item with moves stopped being tracked';
  EXCEPTION WHEN check_violation THEN NULL; END;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM stock_batches WHERE id = ba) OR EXISTS (SELECT 1 FROM batch_balances WHERE batch_id = ba) THEN
    RAISE EXCEPTION 'FAIL T100k: company B saw company A''s batch';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T100 a tracked item''s every move names its own batch, and a batch never goes below zero';
END $$;

-- T101: reorder levels (075). A maximum level is never below the reorder point; a preferred supplier is one of the
-- company's own suppliers, never a customer only or another company's. Company A as f1: item b1, supplier c8, customer c1.
DO $$
DECLARE
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  other uuid;
BEGIN
  BEGIN
    UPDATE items SET reorder_point = 50, max_level = 40 WHERE id = item;
    RAISE EXCEPTION 'FAIL T101a: a maximum level was set below the reorder point';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE items SET preferred_supplier_id = c1 WHERE id = item;
    RAISE EXCEPTION 'FAIL T101b: a customer that is not a supplier was made the preferred supplier';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- Another company's supplier, should one be visible (row-level security hides them from this role, so the rule
  -- is the last line of defence, not the only one).
  SELECT id INTO other FROM contacts WHERE company_id <> app_company_id() AND is_vendor LIMIT 1;
  IF other IS NOT NULL THEN
    BEGIN
      UPDATE items SET preferred_supplier_id = other WHERE id = item;
      RAISE EXCEPTION 'FAIL T101c: another company''s supplier was made the preferred supplier';
    EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL; END;
  END IF;
  UPDATE items SET reorder_point = 50, max_level = 200, preferred_supplier_id = c8 WHERE id = item;
  UPDATE items SET reorder_point = NULL, max_level = NULL, preferred_supplier_id = NULL WHERE id = item;
  RAISE NOTICE 'PASS T101 a maximum level is never below the reorder point, and the preferred supplier is the company''s own supplier';
END $$;

-- T102: bank rules (076). A rule is for one of the company's money accounts, categorises to an active account of the
-- company, names a contact of the company, has words to look for and an amount range the right way round; another
-- company never sees it. Company A as f1: petty cash a57, expense account a40, customer c1.
DO $$
DECLARE
  cash constant uuid := '00000000-0000-0000-0000-000000000a57';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  r uuid;
BEGIN
  BEGIN
    INSERT INTO bank_rules (company_id, name, bank_account_id, contains, target_account_id) VALUES (app_company_id(), 'T102', acc, 'FEE', cash);
    RAISE EXCEPTION 'FAIL T102a: a bank rule was for an account that holds no money';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_rules (company_id, name, contains, target_account_id) VALUES (app_company_id(), 'T102', '  ', acc);
    RAISE EXCEPTION 'FAIL T102b: a bank rule had no words to look for';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_rules (company_id, name, contains, target_account_id, amount_min, amount_max) VALUES (app_company_id(), 'T102', 'FEE', acc, 50, 10);
    RAISE EXCEPTION 'FAIL T102c: a bank rule''s amount range was the wrong way round';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO bank_rules (company_id, name, contains, target_account_id) VALUES (app_company_id(), 'T102', 'FEE', gen_random_uuid());
    RAISE EXCEPTION 'FAIL T102d: a bank rule categorised to an account that is not the company''s';
  EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL; END;
  INSERT INTO bank_rules (company_id, name, bank_account_id, direction, contains, target_account_id, contact_id, amount_max)
    VALUES (app_company_id(), 'T102 fees', cash, 'out', 'SERVICE FEE', acc, c1, 100) RETURNING id INTO r;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM bank_rules WHERE id = r) THEN
    RAISE EXCEPTION 'FAIL T102e: company B saw company A''s bank rule';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  DELETE FROM bank_rules WHERE id = r;
  RAISE NOTICE 'PASS T102 a bank rule names the company''s own accounts and contact, with words and a sound amount range';
END $$;

-- T103: recurring invoices (077). A template is for a customer of the company, makes its invoices as the person who
-- set it up, has lines and a sound schedule; an invoice made from it is for its company and customer; the due list
-- answers nothing inside a company; another company never sees a template. Company A as f1, customer c1, supplier c8.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  f2 constant uuid := '00000000-0000-0000-0000-0000000000f2';
  r constant uuid := '00000000-0000-0000-0000-000000000f10';
  lines constant jsonb := '[{"description": "Retainer", "quantity": "1", "unit_price": "5000", "account_id": "00000000-0000-0000-0000-000000000a40"}]';
BEGIN
  BEGIN
    INSERT INTO recurring_invoices (company_id, name, contact_id, currency, lines, frequency, next_on, posts_as)
      VALUES (app_company_id(), 'T103 retainer', c8, 'ETB', lines, 'monthly', '2027-12-01', f1);
    RAISE EXCEPTION 'FAIL T103a: a recurring invoice was made for a contact that is not a customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO recurring_invoices (company_id, name, contact_id, currency, lines, frequency, next_on, posts_as)
      VALUES (app_company_id(), 'T103 retainer', c1, 'ETB', lines, 'monthly', '2027-12-01', f2);
    RAISE EXCEPTION 'FAIL T103b: a recurring invoice was set up to make its invoices as someone else';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO recurring_invoices (company_id, name, contact_id, currency, lines, frequency, next_on, posts_as)
      VALUES (app_company_id(), 'T103 retainer', c1, 'ETB', '[]', 'monthly', '2027-12-01', f1);
    RAISE EXCEPTION 'FAIL T103c: a recurring invoice had no lines';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO recurring_invoices (company_id, name, contact_id, currency, lines, frequency, next_on, ends_on, posts_as)
      VALUES (app_company_id(), 'T103 retainer', c1, 'ETB', lines, 'monthly', '2027-12-01', '2027-11-01', f1);
    RAISE EXCEPTION 'FAIL T103d: a recurring invoice ended before its next date';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO recurring_invoices (id, company_id, name, contact_id, currency, lines, frequency, next_on, posts_as)
    VALUES (r, app_company_id(), 'T103 retainer', c1, 'ETB', lines, 'monthly', '2027-12-01', f1);

  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, recurring_invoice_id) VALUES (app_company_id(), 'invoice', c8, '2027-12-01', 'ETB', r);
    RAISE EXCEPTION 'FAIL T103e: an invoice from a recurring invoice was for another contact';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, recurring_invoice_id) VALUES (app_company_id(), 'quote', c1, '2027-12-01', 'ETB', r);
    RAISE EXCEPTION 'FAIL T103f: a quote was made from a recurring invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO documents (company_id, type, contact_id, issue_date, currency, recurring_invoice_id) VALUES (app_company_id(), 'invoice', c1, '2027-12-01', 'ETB', r);
  IF EXISTS (SELECT 1 FROM recurring_invoices_due('2099-01-01')) THEN
    RAISE EXCEPTION 'FAIL T103g: the due list answered inside a company';
  END IF;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM recurring_invoices WHERE id = r) THEN
    RAISE EXCEPTION 'FAIL T103h: company B saw company A''s recurring invoice';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T103 a recurring invoice is for a customer, made as its owner, and its invoices are its customer''s';
END $$;

-- T104: bad debts (078). A write-off is of a posted invoice of the company, never more than it owes, dated on or after
-- it, with its own bad-debt entry; another company never sees one. (Writing off and reversing, which need a posted
-- entry, are checked end to end in BadDebtTest.) Company A as f1, customer c1.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  inv constant uuid := '00000000-0000-0000-0000-000000000f20';
  draft constant uuid := '00000000-0000-0000-0000-000000000f21';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total, amount_due) VALUES
    (inv, app_company_id(), 'invoice', c1, '2027-10-01', 'ETB', 500, 500), (draft, app_company_id(), 'invoice', c1, '2027-10-01', 'ETB', 300, 300);
  UPDATE documents SET status = 'posted', number = 'INV-T104-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = inv;

  BEGIN
    INSERT INTO invoice_write_offs (company_id, document_id, amount, written_off_on, reason, entry_id, written_off_by)
      VALUES (app_company_id(), draft, 100, '2027-10-05', 'Customer gone', '00000000-0000-0000-0000-00000000e001', f1);
    RAISE EXCEPTION 'FAIL T104a: a bad debt was written off a draft invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO invoice_write_offs (company_id, document_id, amount, written_off_on, reason, entry_id, written_off_by)
      VALUES (app_company_id(), inv, 600, '2027-10-05', 'Customer gone', '00000000-0000-0000-0000-00000000e001', f1);
    RAISE EXCEPTION 'FAIL T104b: more was written off than the invoice owes';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO invoice_write_offs (company_id, document_id, amount, written_off_on, reason, entry_id, written_off_by)
      VALUES (app_company_id(), inv, 100, '2027-09-01', 'Customer gone', '00000000-0000-0000-0000-00000000e001', f1);
    RAISE EXCEPTION 'FAIL T104c: a write-off was dated before its invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO invoice_write_offs (company_id, document_id, amount, written_off_on, reason, entry_id, written_off_by)
      VALUES (app_company_id(), inv, 100, '2027-10-05', 'Customer gone', '00000000-0000-0000-0000-00000000e001', f1);
    RAISE EXCEPTION 'FAIL T104d: a write-off named an entry that is not its own bad-debt entry';
  EXCEPTION WHEN check_violation THEN NULL; END;
  IF (SELECT amount_due FROM documents WHERE id = inv) <> 500 THEN
    RAISE EXCEPTION 'FAIL T104e: a refused write-off changed what the invoice owes';
  END IF;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM invoice_write_offs WHERE document_id = inv) THEN
    RAISE EXCEPTION 'FAIL T104f: company B saw company A''s write-offs';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T104 a bad debt is written off a posted invoice, within what it owes, with its own entry';
END $$;

-- T105: interest on late payment (079). An interest invoice names a posted invoice of the company for the same customer
-- and currency, a period from its due date on, and never days already charged by a live one (a void one frees them);
-- another company never sees one. Company A as f1, customers c1 and c4.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c4 constant uuid := '00000000-0000-0000-0000-0000000000c4';
  src constant uuid := '00000000-0000-0000-0000-000000000f30';
  i1 constant uuid := '00000000-0000-0000-0000-000000000f31';
  i2 constant uuid := '00000000-0000-0000-0000-000000000f32';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, due_date, currency, total, amount_due)
    VALUES (src, app_company_id(), 'invoice', c1, '2027-09-01', '2027-10-01', 'ETB', 1000, 1000);

  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
      VALUES (app_company_id(), 'invoice', c1, '2027-11-01', 'ETB', src, '2027-10-01', '2027-11-01');
    RAISE EXCEPTION 'FAIL T105a: interest was charged on a draft invoice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'posted', number = 'INV-T105-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = src;

  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
      VALUES (app_company_id(), 'invoice', c4, '2027-11-01', 'ETB', src, '2027-10-01', '2027-11-01');
    RAISE EXCEPTION 'FAIL T105b: interest was charged to another customer';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
      VALUES (app_company_id(), 'invoice', c1, '2027-11-01', 'ETB', src, '2027-09-15', '2027-11-01');
    RAISE EXCEPTION 'FAIL T105c: interest ran from before the invoice fell due';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
      VALUES (app_company_id(), 'invoice', c1, '2027-11-01', 'ETB', src, '2027-11-01', '2027-10-01');
    RAISE EXCEPTION 'FAIL T105d: an interest period ended before it began';
  EXCEPTION WHEN check_violation THEN NULL; END;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
    VALUES (i1, app_company_id(), 'invoice', c1, '2027-11-01', 'ETB', src, '2027-10-01', '2027-11-01');
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
      VALUES (app_company_id(), 'invoice', c1, '2027-11-15', 'ETB', src, '2027-10-20', '2027-11-15');
    RAISE EXCEPTION 'FAIL T105e: the same days were charged twice';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- The next period starts where the last one ended.
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
    VALUES (i2, app_company_id(), 'invoice', c1, '2027-12-01', 'ETB', src, '2027-11-01', '2027-12-01');
  -- A void charge frees its days.
  UPDATE documents SET status = 'void' WHERE id = i1;
  INSERT INTO documents (company_id, type, contact_id, issue_date, currency, interest_on_document_id, interest_from, interest_through)
    VALUES (app_company_id(), 'invoice', c1, '2027-11-01', 'ETB', src, '2027-10-01', '2027-11-01');

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM documents WHERE interest_on_document_id = src) THEN
    RAISE EXCEPTION 'FAIL T105f: company B saw company A''s interest invoices';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  RAISE NOTICE 'PASS T105 interest is charged on a posted invoice to its customer, from its due date, never twice for a day';
END $$;

-- T106: price lists (080). A list prices the company's own items, never below zero and once each; a customer is put on
-- one of the company's lists in use, never a supplier; another company never sees a list or its prices. Company A as f1,
-- customer c1, supplier c8, item b1; company B's item b8.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  b1 constant uuid := '00000000-0000-0000-0000-0000000000b1';
  b8 constant uuid := '00000000-0000-0000-0000-0000000000b8';
  pl constant uuid := '00000000-0000-0000-0000-000000000f40';
  old constant uuid := '00000000-0000-0000-0000-000000000f41';
BEGIN
  INSERT INTO price_lists (id, company_id, name, currency) VALUES (pl, app_company_id(), 'T106 wholesale', 'ETB'),
    (old, app_company_id(), 'T106 old list', 'ETB');
  UPDATE price_lists SET is_active = false WHERE id = old;

  BEGIN
    INSERT INTO price_list_prices (company_id, price_list_id, item_id, unit_price) VALUES (app_company_id(), pl, b8, 100);
    RAISE EXCEPTION 'FAIL T106a: a price list priced another company''s item';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO price_list_prices (company_id, price_list_id, item_id, unit_price) VALUES (app_company_id(), pl, b1, -1);
    RAISE EXCEPTION 'FAIL T106b: a price list had a negative price';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO price_list_prices (company_id, price_list_id, item_id, unit_price) VALUES (app_company_id(), pl, b1, 420);
  BEGIN
    INSERT INTO price_list_prices (company_id, price_list_id, item_id, unit_price) VALUES (app_company_id(), pl, b1, 400);
    RAISE EXCEPTION 'FAIL T106c: an item was listed twice on one list';
  EXCEPTION WHEN unique_violation THEN NULL; END;

  BEGIN
    UPDATE contacts SET price_list_id = pl WHERE id = c8;
    RAISE EXCEPTION 'FAIL T106d: a supplier was put on a price list';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE contacts SET price_list_id = old WHERE id = c1;
    RAISE EXCEPTION 'FAIL T106e: a customer was put on a price list no longer in use';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE contacts SET price_list_id = pl WHERE id = c1;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM price_lists WHERE id = pl) OR EXISTS (SELECT 1 FROM price_list_prices WHERE price_list_id = pl) THEN
    RAISE EXCEPTION 'FAIL T106f: company B saw company A''s price list';
  END IF;
  BEGIN
    UPDATE contacts SET price_list_id = pl WHERE company_id = app_company_id() AND is_customer;
    IF FOUND THEN
      RAISE EXCEPTION 'FAIL T106g: company B put its customers on company A''s price list';
    END IF;
  EXCEPTION WHEN check_violation THEN NULL; END;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  UPDATE contacts SET price_list_id = NULL WHERE id = c1;
  RAISE NOTICE 'PASS T106 a price list prices the company''s own items and is for its own customers';
END $$;

-- T107: goods delivered on notes come back on a credit note (081). An invoice that billed 4 delivered bags (moving no
-- stock itself) has 4 to take back: 3 come back, 2 more are refused. Company A as f1, customer c1, item b1 in a1.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  so constant uuid := '00000000-0000-0000-0000-000000000f50';
  sl constant uuid := '00000000-0000-0000-0000-000000000f51';
  inv constant uuid := '00000000-0000-0000-0000-000000000f52';
  il constant uuid := '00000000-0000-0000-0000-000000000f53';
  dn constant uuid := '00000000-0000-0000-0000-000000000f54';
  cn constant uuid := '00000000-0000-0000-0000-000000000f55';
  item constant uuid := '00000000-0000-0000-0000-0000000000b1';
  wh constant uuid := '00000000-0000-0000-0000-0000000000a1';
  acc constant uuid := '00000000-0000-0000-0000-000000000a40';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total) VALUES (so, app_company_id(), 'sales_order', c1, '2027-11-01', 'ETB', 1000);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id) VALUES
    (sl, app_company_id(), so, 1, 'Coffee', 10, 100, acc, 1000, item);
  UPDATE documents SET status = 'accepted', number = 'SO-T107-1' WHERE id = so;
  INSERT INTO delivery_notes (id, company_id, sales_order_id, number, delivered_on, warehouse_id, created_by) VALUES (dn, app_company_id(), so, 'DN-T107-1', '2027-11-02', wh, f1);
  INSERT INTO delivery_note_lines (company_id, delivery_note_id, order_line_id, item_id, quantity) VALUES (app_company_id(), dn, sl, item, 4);

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, source_document_id, total, amount_due) VALUES
    (inv, app_company_id(), 'invoice', c1, '2027-11-03', 'ETB', so, 400, 400);
  INSERT INTO document_lines (id, company_id, document_id, line_no, description, quantity, unit_price, account_id, line_subtotal, item_id, source_line_id) VALUES
    (il, app_company_id(), inv, 1, 'Coffee', 4, 100, acc, 400, item, sl);
  INSERT INTO invoice_delivery_matches (company_id, invoice_id, invoice_line_id, order_line_id, quantity) VALUES (app_company_id(), inv, il, sl, 4);
  UPDATE documents SET status = 'posted', number = 'INV-T107-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = inv;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, original_document_id, restock) VALUES
    (cn, app_company_id(), 'credit_note', c1, '2027-11-04', 'ETB', inv, true);
  INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES (app_company_id(), item, wh, '2027-11-04', 'sale_return', 3, 50, cn);
  BEGIN
    INSERT INTO stock_moves (company_id, item_id, warehouse_id, moved_on, kind, quantity, unit_cost, document_id) VALUES (app_company_id(), item, wh, '2027-11-04', 'sale_return', 2, 50, cn);
    RAISE EXCEPTION 'FAIL T107: more came back than the invoice billed from its delivery notes';
  EXCEPTION WHEN check_violation THEN NULL; END;
  RAISE NOTICE 'PASS T107 goods delivered on notes come back on a credit note, never more than the invoice billed';
END $$;

-- T108: a credit note corrects a cash sale (082): of the same customer and currency, once posted, never beyond its total;
-- a supplier credit still corrects only a bill. Company A as f1, customer c1, supplier c8, bank account a10.
DO $$
DECLARE
  c1 constant uuid := '00000000-0000-0000-0000-0000000000c1';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  sr constant uuid := '00000000-0000-0000-0000-000000000f60';
  cn constant uuid := '00000000-0000-0000-0000-000000000f61';
  bank constant uuid := '00000000-0000-0000-0000-000000000a10';
BEGIN
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total, amount_due, paid_from_account_id, payment_method)
    VALUES (sr, app_company_id(), 'sales_receipt', c1, '2027-11-10', 'ETB', 300, 0, bank, 'cash');
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, original_document_id) VALUES (app_company_id(), 'credit_note', c1, '2027-11-11', 'ETB', sr);
    RAISE EXCEPTION 'FAIL T108a: a cash sale not yet posted was credited';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET status = 'posted', number = 'SR-T108-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = sr;

  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, original_document_id, total, amount_due)
    VALUES (cn, app_company_id(), 'credit_note', c1, '2027-11-11', 'ETB', sr, 400, 400);
  BEGIN
    UPDATE documents SET status = 'posted', number = 'CN-T108-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = cn;
    RAISE EXCEPTION 'FAIL T108b: a cash sale was credited by more than its total';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, original_document_id) VALUES (app_company_id(), 'vendor_credit', c8, '2027-11-11', 'ETB', sr);
    RAISE EXCEPTION 'FAIL T108c: a supplier credit corrected a cash sale';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE documents SET total = 300, amount_due = 300 WHERE id = cn;
  UPDATE documents SET status = 'posted', number = 'CN-T108-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = cn;
  RAISE NOTICE 'PASS T108 a credit note corrects a posted cash sale of its customer, never beyond it';
END $$;

-- T109: expense claims (083). A staff payee is a supplier record for a member, once per company; a claim is a bill to
-- the claimant's own payee, never posted by the claimant. Company A: f1 the owner (claimant), another user (c2) approving,
-- f2 a member of company B only; supplier c8.
DO $$
DECLARE
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  f2 constant uuid := '00000000-0000-0000-0000-0000000000f2';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  payee constant uuid := '00000000-0000-0000-0000-000000000f70';
  claim constant uuid := '00000000-0000-0000-0000-000000000f71';
BEGIN
  BEGIN
    INSERT INTO contacts (company_id, name, is_vendor, employee_user_id) VALUES (app_company_id(), 'T109 outsider', true, f2);
    RAISE EXCEPTION 'FAIL T109a: a staff payee named someone who is not a member of the company';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO contacts (company_id, name, is_customer, employee_user_id) VALUES (app_company_id(), 'T109 not a supplier', true, f1);
    RAISE EXCEPTION 'FAIL T109b: a staff payee was not a supplier record';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO contacts (id, company_id, name, is_vendor, employee_user_id) VALUES (payee, app_company_id(), 'T109 claimant', true, f1);
  BEGIN
    INSERT INTO contacts (company_id, name, is_vendor, employee_user_id) VALUES (app_company_id(), 'T109 twice', true, f1);
    RAISE EXCEPTION 'FAIL T109c: a member was a staff payee twice';
  EXCEPTION WHEN unique_violation THEN NULL; END;

  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, claimed_by) VALUES (app_company_id(), 'bill', c8, '2027-11-01', 'ETB', f1);
    RAISE EXCEPTION 'FAIL T109d: a claim was a bill to someone other than the claimant';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO documents (company_id, type, contact_id, issue_date, currency, claimed_by) VALUES (app_company_id(), 'invoice', payee, '2027-11-01', 'ETB', f1);
    RAISE EXCEPTION 'FAIL T109e: a claim was not a bill';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency, total, amount_due, claimed_by)
    VALUES (claim, app_company_id(), 'bill', payee, '2027-11-01', 'ETB', 350, 350, f1);
  BEGIN
    UPDATE documents SET status = 'posted', number = 'BILL-T109-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = claim;
    RAISE EXCEPTION 'FAIL T109f: the claimant posted their own claim';
  EXCEPTION WHEN check_violation THEN NULL; END;
  -- Someone else approves it.
  PERFORM set_config('app.user_id', '00000000-0000-0000-0000-0000000000c2', true);
  UPDATE documents SET status = 'posted', number = 'BILL-T109-1', journal_entry_id = '00000000-0000-0000-0000-00000000e001' WHERE id = claim;
  PERFORM set_config('app.user_id', f1::text, true);
  RAISE NOTICE 'PASS T109 an expense claim is a bill to the claimant''s own staff payee, approved by someone else';
END $$;

-- T110: purchase requisitions (084). Asked for by the member asking, kept as asked; approved by someone else, who is
-- the one recorded; moved only forward; ordered into a purchase order of the company; another company never sees one.
-- Company A as f1 (the asker); another user (c2) deciding; supplier c8.
DO $$
DECLARE
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  c2 constant uuid := '00000000-0000-0000-0000-0000000000c2';
  c8 constant uuid := '00000000-0000-0000-0000-0000000000c8';
  r constant uuid := '00000000-0000-0000-0000-000000000f80';
  po constant uuid := '00000000-0000-0000-0000-000000000f81';
  lines constant jsonb := '[{"description": "Paper cups, 1,000", "quantity": "4", "estimated_price": "350"}]';
BEGIN
  BEGIN
    INSERT INTO purchase_requisitions (company_id, requested_by, reason, lines) VALUES (app_company_id(), c2, 'For the cafe', lines);
    RAISE EXCEPTION 'FAIL T110a: a requisition was asked for in someone else''s name';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO purchase_requisitions (company_id, requested_by, reason, lines) VALUES (app_company_id(), f1, 'For the cafe', '[]');
    RAISE EXCEPTION 'FAIL T110b: a requisition asked for nothing';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO purchase_requisitions (id, company_id, requested_by, reason, lines) VALUES (r, app_company_id(), f1, 'For the cafe', lines);
  BEGIN
    UPDATE purchase_requisitions SET lines = '[{"description": "More cups", "quantity": "40"}]' WHERE id = r;
    RAISE EXCEPTION 'FAIL T110c: what a requisition asked for was changed';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE purchase_requisitions SET status = 'approved', decided_by = f1, decided_at = now() WHERE id = r;
    RAISE EXCEPTION 'FAIL T110d: the asker approved their own requisition';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE purchase_requisitions SET status = 'ordered', purchase_order_id = po WHERE id = r;
    RAISE EXCEPTION 'FAIL T110e: a requisition was ordered before it was approved';
  EXCEPTION WHEN check_violation THEN NULL; END;

  PERFORM set_config('app.user_id', c2::text, true);
  BEGIN
    UPDATE purchase_requisitions SET status = 'approved', decided_by = '00000000-0000-0000-0000-0000000000f2', decided_at = now() WHERE id = r;
    RAISE EXCEPTION 'FAIL T110f: a requisition recorded someone else as the one who approved it';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    UPDATE purchase_requisitions SET status = 'withdrawn' WHERE id = r;
    RAISE EXCEPTION 'FAIL T110g: a requisition was withdrawn by someone other than the asker';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE purchase_requisitions SET status = 'approved', decided_by = c2, decided_at = now() WHERE id = r;
  BEGIN
    UPDATE purchase_requisitions SET status = 'ordered', purchase_order_id = '00000000-0000-0000-0000-00000000e001' WHERE id = r;
    RAISE EXCEPTION 'FAIL T110h: an ordered requisition named something that is not a purchase order';
  EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL; END;
  INSERT INTO documents (id, company_id, type, contact_id, issue_date, currency) VALUES (po, app_company_id(), 'purchase_order', c8, '2027-11-05', 'ETB');
  UPDATE purchase_requisitions SET status = 'ordered', purchase_order_id = po WHERE id = r;
  BEGIN
    UPDATE purchase_requisitions SET status = 'requested' WHERE id = r;
    RAISE EXCEPTION 'FAIL T110i: an ordered requisition went back to requested';
  EXCEPTION WHEN check_violation THEN NULL; END;
  PERFORM set_config('app.user_id', f1::text, true);

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM purchase_requisitions WHERE id = r) THEN
    RAISE EXCEPTION 'FAIL T110j: company B saw company A''s requisition';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', f1::text, true);
  RAISE NOTICE 'PASS T110 a requisition is asked for by its member, decided by someone else and ordered once approved';
END $$;

-- T111: barcodes (085). A barcode is printable, with no space at either end; within a company it names one item or one
-- unit, never both; another company may use the same one. Company A as f1, item b1; company B's item b8.
DO $$
DECLARE
  b1 constant uuid := '00000000-0000-0000-0000-0000000000b1';
  u1 constant uuid := '00000000-0000-0000-0000-000000000f90';
BEGIN
  BEGIN
    UPDATE items SET barcode = ' 4006381333931' WHERE id = b1;
    RAISE EXCEPTION 'FAIL T111a: a barcode began with a space';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE items SET barcode = '4006381333931' WHERE id = b1;
  INSERT INTO item_units (id, company_id, item_id, name, factor, created_by) VALUES (u1, app_company_id(), b1, 'T111 carton', 12, '00000000-0000-0000-0000-0000000000f1');
  BEGIN
    UPDATE item_units SET barcode = '4006381333931' WHERE id = u1;
    RAISE EXCEPTION 'FAIL T111b: a unit took the barcode of an item';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  UPDATE item_units SET barcode = '14006381333938' WHERE id = u1;
  BEGIN
    INSERT INTO items (company_id, name, type, barcode) VALUES (app_company_id(), 'T111 other', 'service', '14006381333938');
    RAISE EXCEPTION 'FAIL T111c: an item took the barcode of a unit';
  EXCEPTION WHEN unique_violation THEN NULL; END;
  BEGIN
    INSERT INTO items (company_id, name, type, barcode) VALUES (app_company_id(), 'T111 twin', 'service', '4006381333931');
    RAISE EXCEPTION 'FAIL T111d: two items had one barcode';
  EXCEPTION WHEN unique_violation THEN NULL; END;

  -- Another company's item may carry the same barcode (as the owner of the test, to reach company B's row).
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  UPDATE items SET barcode = '4006381333931' WHERE id = '00000000-0000-0000-0000-0000000000b8';
  IF NOT FOUND THEN
    RAISE EXCEPTION 'FAIL T111e: company B could not use a barcode company A uses';
  END IF;
  UPDATE items SET barcode = NULL WHERE id = '00000000-0000-0000-0000-0000000000b8';
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f1', true);
  UPDATE item_units SET barcode = NULL, is_active = false WHERE id = u1;
  UPDATE items SET barcode = NULL WHERE id = b1;
  RAISE NOTICE 'PASS T111 a barcode names one item or one unit within a company';
END $$;

-- T112: attachments on journal entries (086). An attachment belongs to exactly one thing of the company, a document or a
-- journal entry; another company's entry is refused; a journal entry keeps at most 20; another company sees none.
-- Company A as f1, entry e001.
DO $$
DECLARE
  f1 constant uuid := '00000000-0000-0000-0000-0000000000f1';
  e1 constant uuid := '00000000-0000-0000-0000-00000000e001';
  eb uuid;
  i int;
BEGIN
  eb := gen_random_uuid();   -- not one of company A's entries (another company's, or none)
  BEGIN
    INSERT INTO attachments (company_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), 'nothing.pdf', 'application/pdf', 10, repeat('a', 64), repeat('1', 40), f1);
    RAISE EXCEPTION 'FAIL T112a: an attachment belonged to nothing';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO attachments (company_id, document_id, journal_entry_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), (SELECT id FROM documents WHERE company_id = app_company_id() LIMIT 1), e1, 'both.pdf', 'application/pdf', 10, repeat('a', 64), repeat('2', 40), f1);
    RAISE EXCEPTION 'FAIL T112b: an attachment belonged to a document and a journal entry';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO attachments (company_id, journal_entry_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), eb, 'theirs.pdf', 'application/pdf', 10, repeat('a', 64), repeat('3', 40), f1);
    RAISE EXCEPTION 'FAIL T112c: an attachment was put on a journal entry that is not the company''s';
  EXCEPTION WHEN check_violation OR foreign_key_violation THEN NULL; END;

  FOR i IN 1..20 LOOP
    INSERT INTO attachments (company_id, journal_entry_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), e1, 'minute-' || i || '.pdf', 'application/pdf', 10, repeat('a', 64), lpad(to_hex(4000 + i), 40, 'b'), f1);
  END LOOP;
  BEGIN
    INSERT INTO attachments (company_id, journal_entry_id, file_name, content_type, size_bytes, sha256, storage_key, created_by)
      VALUES (app_company_id(), e1, 'minute-21.pdf', 'application/pdf', 10, repeat('a', 64), repeat('c', 40), f1);
    RAISE EXCEPTION 'FAIL T112d: a journal entry kept more than 20 attachments';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE attachments SET removed_at = now(), removed_by = f1 WHERE journal_entry_id = e1 AND removed_at IS NULL;

  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000b', true), set_config('app.user_id', '00000000-0000-0000-0000-0000000000f2', true);
  IF EXISTS (SELECT 1 FROM attachments WHERE journal_entry_id = e1) THEN
    RAISE EXCEPTION 'FAIL T112e: company B saw company A''s journal attachments';
  END IF;
  PERFORM set_config('app.company_id', '00000000-0000-0000-0000-00000000000a', true), set_config('app.user_id', f1::text, true);
  RAISE NOTICE 'PASS T112 an attachment belongs to one document or journal entry of the company, at most 20 each';
END $$;
SELECT set_config('app.user_id', '00000000-0000-0000-0000-0000000000c1', false),
       set_config('app.company_id', '00000000-0000-0000-0000-00000000000c', false) \gset ignore_

-- T45: demo accounts are written only by the owner role, each is an active member of its demo company with its
-- role, and never joins another company (018). Uses c3 of company C (a bookkeeper since T26).
DO $$ BEGIN
  PERFORM count(*) FROM demo_accounts;   -- sign-in reads it before anyone is in the context
  BEGIN
    INSERT INTO demo_accounts (user_id, company_id, role) VALUES ('00000000-0000-0000-0000-0000000000c3', '00000000-0000-0000-0000-00000000000c', 'bookkeeper');
    RAISE EXCEPTION 'FAIL T45a: the application role made an account a demo account';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
END $$;
RESET ROLE;
DO $$ BEGIN
  BEGIN
    INSERT INTO demo_accounts (user_id, company_id, role) VALUES ('00000000-0000-0000-0000-0000000000c3', '00000000-0000-0000-0000-00000000000c', 'owner');
    RAISE EXCEPTION 'FAIL T45b: a demo account was stored with a role it does not have';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO demo_accounts (user_id, company_id, role) VALUES ('00000000-0000-0000-0000-0000000000c3', '00000000-0000-0000-0000-00000000000a', 'bookkeeper');
    RAISE EXCEPTION 'FAIL T45c: a demo account was stored for a company it is not a member of';
  EXCEPTION WHEN check_violation THEN NULL; END;
  INSERT INTO demo_accounts (user_id, company_id, role) VALUES ('00000000-0000-0000-0000-0000000000c3', '00000000-0000-0000-0000-00000000000c', 'bookkeeper');
  BEGIN
    INSERT INTO memberships (company_id, user_id, role) VALUES ('00000000-0000-0000-0000-00000000000a', '00000000-0000-0000-0000-0000000000c3', 'viewer');
    RAISE EXCEPTION 'FAIL T45d: a demo account joined another company';
  EXCEPTION WHEN check_violation THEN NULL; END;
END $$;
SET ROLE ledgerly_web;
DO $$ DECLARE n int; BEGIN
  IF NOT EXISTS (SELECT 1 FROM demo_accounts WHERE user_id = '00000000-0000-0000-0000-0000000000c3') THEN
    RAISE EXCEPTION 'FAIL T45e: the application role cannot read the demo accounts';
  END IF;
  BEGIN
    UPDATE demo_accounts SET role = 'owner';
    RAISE EXCEPTION 'FAIL T45f: the application role changed a demo account';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  BEGIN
    DELETE FROM demo_accounts;
    RAISE EXCEPTION 'FAIL T45g: the application role removed a demo account';
  EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  RAISE NOTICE 'PASS T45 demo accounts are set only by the owner role, match their membership and stay in the demo company';
END $$;
RESET ROLE;
DELETE FROM demo_accounts;
SET ROLE ledgerly_web;

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

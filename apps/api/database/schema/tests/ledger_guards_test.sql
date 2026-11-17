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

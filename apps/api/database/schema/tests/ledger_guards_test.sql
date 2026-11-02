-- Self-checking tests for the database-level ledger guards and tenant isolation.
-- Run against an EMPTY database that has 001..005 applied, as the owner/superuser:
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

INSERT INTO organisations (id, name) VALUES ('00000000-0000-0000-0000-0000000000a0', 'Test Org');
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

-- T9: a posted document is frozen except status and amount_due
DO $$ BEGIN
  INSERT INTO contacts (id, company_id, name, is_customer)
    VALUES ('00000000-0000-0000-0000-0000000000c1', app_company_id(), 'Sheger Grand Hotel', true);
  INSERT INTO documents (id, company_id, type, number, contact_id, issue_date, currency, status, total, amount_due, journal_entry_id)
    VALUES ('00000000-0000-0000-0000-0000000000d1', app_company_id(), 'invoice', 'INV-0001', '00000000-0000-0000-0000-0000000000c1',
            '2026-11-02', 'ETB', 'posted', 501745, 501745, '00000000-0000-0000-0000-00000000e001');
  UPDATE documents SET amount_due = 0 WHERE id = '00000000-0000-0000-0000-0000000000d1';
  BEGIN
    UPDATE documents SET total = 1 WHERE id = '00000000-0000-0000-0000-0000000000d1';
    RAISE EXCEPTION 'FAIL T9: posted document total changed';
  EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'PASS T9 posted document frozen; amount_due may change';
  END;
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

RESET ROLE;
\echo 'All ledger guard tests passed.'

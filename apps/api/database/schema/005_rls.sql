-- Ledgerly core schema, part 5: tenant isolation with PostgreSQL row-level security.
--
-- Migrations run as the table OWNER (e.g. ledgerly_owner), which bypasses RLS.
-- The application connects as a login role that is a member of ledgerly_app and is NOT the owner,
-- so every query it runs is filtered by these policies.
--
-- Per request the API sets:
--   app.user_id    = the signed-in user           (lets them list their own companies)
--   app.company_id = the company chosen by X-Company-Id, after the membership check
-- with set_config(..., false) and resets both when the request ends.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ledgerly_app') THEN
        CREATE ROLE ledgerly_app NOLOGIN;
    END IF;
END $$;

CREATE FUNCTION app_company_id() RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT nullif(current_setting('app.company_id', true), '')::uuid
$$;

CREATE FUNCTION app_user_id() RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT nullif(current_setting('app.user_id', true), '')::uuid
$$;

-- Tables keyed by company_id get the same policy.
DO $$
DECLARE
    t text;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'company_sequences', 'accounts', 'journal_entries', 'journal_lines', 'outbox_events',
        'contacts', 'items', 'warehouses', 'stock_moves', 'documents', 'document_lines', 'payments', 'payment_allocations',
        'bank_transactions', 'einvoice_submissions', 'audit_log'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format(
            'CREATE POLICY tenant_isolation ON %I USING (company_id = app_company_id()) WITH CHECK (company_id = app_company_id())',
            t);
    END LOOP;
END $$;

-- Companies: the current company, plus any company the user is a member of (for the company switcher).
ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
CREATE POLICY company_access ON companies
    USING (id = app_company_id()
           OR id IN (SELECT company_id FROM memberships WHERE user_id = app_user_id()))
    WITH CHECK (id = app_company_id());

-- Memberships: everyone in the current company, plus the user's own memberships elsewhere.
ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
CREATE POLICY membership_access ON memberships
    USING (company_id = app_company_id() OR user_id = app_user_id())
    WITH CHECK (company_id = app_company_id());

-- Tax rates: country-pack rows (company_id NULL) are readable by all; companies write only their own.
ALTER TABLE tax_rates ENABLE ROW LEVEL SECURITY;
CREATE POLICY tax_rates_read ON tax_rates FOR SELECT
    USING (company_id IS NULL OR company_id = app_company_id());
CREATE POLICY tax_rates_write ON tax_rates FOR INSERT
    WITH CHECK (company_id = app_company_id());

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ledgerly_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ledgerly_app;

-- Ledgerly core schema, part 7: tenant isolation with PostgreSQL row-level security, and grants.
--
-- Migrations run as the table OWNER (e.g. ledgerly_owner), which bypasses RLS.
-- The application connects as a login role that is a member of ledgerly_app and is NOT the owner,
-- so every query it runs is filtered by these policies.
--
-- The API runs every request and every queued job inside ONE database transaction and sets, at its start:
--   set_config('app.user_id',    <signed-in user>, true)   -- true = local to this transaction (SET LOCAL)
--   set_config('app.company_id', <chosen company>, true)   -- after the membership check
-- The settings vanish at COMMIT or ROLLBACK, so a pooled connection (PgBouncer, transaction mode) can
-- never carry one company's context into the next request. With no context set, the policies match nothing.

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

-- Tables keyed by company_id get the same policy. (SELECT app_company_id()) is evaluated once per query,
-- not once per row, and lets the planner prune to the company's partition.
DO $$
DECLARE
    t text;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'company_sequences', 'idempotency_keys', 'fiscal_years', 'accounts', 'journal_entries', 'journal_lines',
        'account_balances', 'outbox_events', 'contacts', 'items', 'warehouses', 'stock_moves', 'documents',
        'document_lines', 'document_versions', 'payments', 'payment_allocations', 'bank_transactions',
        'einvoice_submissions', 'audit_log'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format(
            'CREATE POLICY tenant_isolation ON %I USING (company_id = (SELECT app_company_id())) '
            'WITH CHECK (company_id = (SELECT app_company_id()))', t);
    END LOOP;
END $$;

-- Companies: the current company, plus any company the user is a member of (for the company switcher).
ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
CREATE POLICY company_access ON companies
    USING (id = (SELECT app_company_id())
           OR id IN (SELECT company_id FROM memberships WHERE user_id = (SELECT app_user_id()) AND status = 'active'))
    WITH CHECK (id = (SELECT app_company_id()));

-- Memberships: everyone in the current company, plus the user's own memberships elsewhere.
ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
CREATE POLICY membership_access ON memberships
    USING (company_id = (SELECT app_company_id()) OR user_id = (SELECT app_user_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));

-- Organisations: the one the user created at sign-up, or the one that owns a company the user can see.
ALTER TABLE organisations ENABLE ROW LEVEL SECURITY;
CREATE POLICY organisation_read ON organisations FOR SELECT
    USING (created_by = (SELECT app_user_id()) OR id IN (SELECT organisation_id FROM companies));
CREATE POLICY organisation_create ON organisations FOR INSERT
    WITH CHECK (created_by = (SELECT app_user_id()) AND status = 'trial');
CREATE POLICY organisation_rename ON organisations FOR UPDATE
    USING (id IN (SELECT organisation_id FROM companies WHERE id = (SELECT app_company_id())));

-- Tax rates: country-pack rows (company_id NULL) are readable by all; companies write only their own.
ALTER TABLE tax_rates ENABLE ROW LEVEL SECURITY;
CREATE POLICY tax_rates_read ON tax_rates FOR SELECT
    USING (company_id IS NULL OR company_id = (SELECT app_company_id()));
CREATE POLICY tax_rates_write ON tax_rates FOR INSERT
    WITH CHECK (company_id = (SELECT app_company_id()));

-- Grants. Partitions are never granted directly: the application reaches them only through the parent
-- table, where the policies above apply.
DO $$
DECLARE
    t text;
BEGIN
    FOR t IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition LOOP
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO ledgerly_app', t);
    END LOOP;
END $$;
DO $$
DECLARE
    t text;
BEGIN
    -- Belt and braces: remove anything a default privilege may have granted on a partition.
    FOR t IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relispartition AND c.relkind = 'r' LOOP
        EXECUTE format('REVOKE ALL ON %I FROM ledgerly_app', t);
    END LOOP;
END $$;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ledgerly_app;

-- Tighter than the default for tables the application must never rewrite.
REVOKE INSERT, UPDATE, DELETE ON account_balances, state_transitions FROM ledgerly_app;  -- written by trigger / migration only
REVOKE UPDATE, DELETE ON journal_lines, audit_log, stock_moves, document_versions FROM ledgerly_app;
REVOKE DELETE ON journal_entries, organisations, companies FROM ledgerly_app;
REVOKE UPDATE ON organisations FROM ledgerly_app;
GRANT  UPDATE (name, updated_at) ON organisations TO ledgerly_app;  -- plan and status change through billing (owner role)

GRANT EXECUTE ON FUNCTION audit_log_verify(uuid) TO ledgerly_app;

-- Ledgerly core schema, part 45: saved and scheduled reports (Module 10).
--
-- A person saves a report with its period (a custom range, or one that rolls: this month, last month, this or last
-- quarter, the fiscal year to date) and its options, and may have it sent weekly or monthly. Sending is an email to
-- that person with a link to the report: no figures leave the app, and the link needs them signed in. Rules here:
--   1. A saved report is its owner's alone: the company's and the person's (row-level security on both).
--   2. Its owner is an active member of the company, and the person signed in when it is saved.
--   3. A custom period has its dates, in order; a rolling period has none. A schedule has its next date.
--   4. The scheduler finds what is due through saved_reports_due(), which gives ids only and works only outside any
--      company's context.

CREATE TABLE saved_reports (
    id           uuid         PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid         NOT NULL REFERENCES companies (id),
    user_id      uuid         NOT NULL REFERENCES users (id),
    name         text         NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 80),
    report       text         NOT NULL CHECK (report IN ('profit-and-loss', 'balance-sheet', 'trial-balance', 'general-ledger', 'cash-flow',
                                   'changes-in-equity', 'financial-health', 'profit-by-dimension', 'sales', 'purchases', 'ar-ageing', 'ap-ageing',
                                   'inventory-valuation', 'stock-movement', 'stock-ageing')),
    period       text         NOT NULL CHECK (period IN ('custom', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'year_to_date')),
    from_date    date,
    to_date      date,
    options      jsonb        NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(options) = 'object' AND length(options::text) <= 1000),
    schedule     text         NOT NULL DEFAULT 'none' CHECK (schedule IN ('none', 'weekly', 'monthly')),
    next_send_on date,
    last_sent_at timestamptz,
    created_at   timestamptz  NOT NULL DEFAULT now(),
    updated_at   timestamptz  NOT NULL DEFAULT now(),
    CHECK ((period = 'custom') = (to_date IS NOT NULL)),
    CHECK (period = 'custom' OR from_date IS NULL),
    CHECK (from_date IS NULL OR to_date >= from_date),
    CHECK ((schedule = 'none') = (next_send_on IS NULL))
);
CREATE INDEX saved_reports_owner_idx ON saved_reports (company_id, user_id, name);
CREATE INDEX saved_reports_due_idx ON saved_reports (next_send_on) WHERE schedule <> 'none';

CREATE FUNCTION saved_reports_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.user_id) IS DISTINCT FROM (OLD.company_id, OLD.user_id) THEN
        RAISE EXCEPTION 'A saved report stays with the company and person it was saved by' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND app_user_id() IS NOT NULL AND NEW.user_id IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A report is saved by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = NEW.company_id AND user_id = NEW.user_id AND status = 'active') THEN
        RAISE EXCEPTION 'A saved report belongs to an active member of the company' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER saved_reports_valid BEFORE INSERT OR UPDATE ON saved_reports
    FOR EACH ROW EXECUTE FUNCTION saved_reports_valid();

CREATE FUNCTION saved_reports_due(p_on date)
RETURNS TABLE (company_id uuid, saved_report_id uuid, user_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT s.company_id, s.id, s.user_id FROM saved_reports s
     WHERE s.schedule <> 'none' AND s.next_send_on <= p_on AND app_company_id() IS NULL AND app_user_id() IS NULL
       AND EXISTS (SELECT 1 FROM memberships m WHERE m.company_id = s.company_id AND m.user_id = s.user_id AND m.status = 'active')
     ORDER BY s.next_send_on, s.id LIMIT 1000
$$;
REVOKE ALL ON FUNCTION saved_reports_due(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION saved_reports_due(date) TO {{app_role}};

ALTER TABLE saved_reports ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON saved_reports
    USING (company_id = (SELECT app_company_id()) AND user_id = (SELECT app_user_id()))
    WITH CHECK (company_id = (SELECT app_company_id()) AND user_id = (SELECT app_user_id()));
REVOKE ALL ON saved_reports FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON saved_reports TO {{app_role}};

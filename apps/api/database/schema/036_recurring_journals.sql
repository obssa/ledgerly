-- Ledgerly core schema, part 36: recurring and auto-reversing manual journals (Module 6).
--
-- A recurring journal is a manual journal posted on a schedule (rent, depreciation, a standing accrual). Each posting is
-- an ordinary manual journal (032 applies), naming its template. Rules here:
--   1. A template repeats monthly, quarterly or yearly, from its next date, until its end date if it has one; its lines
--      are at least two.
--   2. An entry made from a template is a manual journal of the template's company.
--   3. A template posts as the person signed in when it was set up, edited or resumed: never as someone else.
--   4. A journal waiting for approval may say when it reverses: after its own date, never changed once asked.

CREATE TABLE recurring_journals (
    id             uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id     uuid          NOT NULL REFERENCES companies (id),
    name           text          NOT NULL CHECK (length(trim(name)) BETWEEN 3 AND 120),
    memo           text          NOT NULL CHECK (length(memo) BETWEEN 3 AND 500),
    lines          jsonb         NOT NULL CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 2 AND 200),
    total          numeric(20,2) NOT NULL CHECK (total > 0),
    frequency      text          NOT NULL CHECK (frequency IN ('monthly', 'quarterly', 'yearly')),
    next_on        date          NOT NULL,
    ends_on        date,
    is_active      boolean       NOT NULL DEFAULT true,
    posted_count   integer       NOT NULL DEFAULT 0 CHECK (posted_count >= 0),
    last_posted_on date,
    last_error     text          CHECK (length(last_error) <= 500),
    posts_as       uuid          NOT NULL REFERENCES users (id),   -- who set it up, or last edited or resumed it
    created_at     timestamptz   NOT NULL DEFAULT now(),
    updated_at     timestamptz   NOT NULL DEFAULT now(),
    CHECK (ends_on IS NULL OR NOT is_active OR next_on <= ends_on)   -- an active template's next date is within its end
);
CREATE INDEX recurring_journals_due_idx ON recurring_journals (next_on) WHERE is_active;

CREATE FUNCTION recurring_journals_posts_as() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF app_user_id() IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.posts_as IS DISTINCT FROM OLD.posts_as)
       AND NEW.posts_as IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A recurring journal posts as the person who set it up, edited or resumed it' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER recurring_journals_posts_as BEFORE INSERT OR UPDATE ON recurring_journals
    FOR EACH ROW EXECUTE FUNCTION recurring_journals_posts_as();

-- An entry naming a template (source manual, source_id = the template) belongs to the template's company.
CREATE FUNCTION journal_entries_recurring_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    template_company uuid;
BEGIN
    IF NEW.source_type = 'manual' AND NEW.source_id IS NOT NULL THEN
        SELECT company_id INTO template_company FROM recurring_journals WHERE id = NEW.source_id;
        IF FOUND AND template_company <> NEW.company_id THEN
            RAISE EXCEPTION 'An entry is made from a recurring journal of its own company' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_recurring_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_recurring_valid();

-- Auto-reversal of a journal waiting for approval (033): kept with what was asked.
ALTER TABLE journal_requests ADD COLUMN reverse_on date;
ALTER TABLE journal_requests ADD CONSTRAINT journal_requests_reverse_after CHECK (reverse_on IS NULL OR reverse_on > entry_date) NOT VALID;

CREATE OR REPLACE FUNCTION journal_requests_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'pending' THEN
            RAISE EXCEPTION 'A request starts waiting for approval' USING ERRCODE = 'check_violation';
        END IF;
        IF app_user_id() IS NOT NULL AND NEW.requested_by IS DISTINCT FROM app_user_id() THEN
            RAISE EXCEPTION 'A request is made by the person signed in' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'pending' THEN
        RAISE EXCEPTION 'This request is already %', OLD.status USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.company_id, NEW.entry_date, NEW.memo, NEW.lines, NEW.total, NEW.requested_by, NEW.created_at, NEW.reverse_on)
       IS DISTINCT FROM (OLD.company_id, OLD.entry_date, OLD.memo, OLD.lines, OLD.total, OLD.requested_by, OLD.created_at, OLD.reverse_on) THEN
        RAISE EXCEPTION 'What was asked does not change: withdraw it and ask again' USING ERRCODE = 'check_violation';
    END IF;
    IF app_user_id() IS NOT NULL AND NEW.decided_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A request is decided by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IN ('approved', 'rejected') AND NEW.decided_by = NEW.requested_by THEN
        RAISE EXCEPTION 'Someone other than the person who asked approves or rejects a journal' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'approved' AND NOT EXISTS (
        SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type = 'manual' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'An approved request names the manual journal it posted' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- The scheduler (ledgerly:post-recurring) runs outside any company, as the application login, so row-level security
-- shows it nothing. This answers only which templates are due and who set them up: ids, nothing of their content. It
-- answers nothing inside a company's context, so a request cannot use it to look across companies.
CREATE FUNCTION recurring_journals_due(p_on date)
RETURNS TABLE (company_id uuid, template_id uuid, posts_as uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT r.company_id, r.id, r.posts_as FROM recurring_journals r
     WHERE r.is_active AND r.next_on <= p_on AND app_company_id() IS NULL AND app_user_id() IS NULL
     ORDER BY r.next_on, r.id LIMIT 1000
$$;
REVOKE ALL ON FUNCTION recurring_journals_due(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION recurring_journals_due(date) TO {{app_role}};

ALTER TABLE recurring_journals ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON recurring_journals
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON recurring_journals FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON recurring_journals TO {{app_role}};

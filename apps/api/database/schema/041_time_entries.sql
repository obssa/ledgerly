-- Evenbooks core schema, part 41: time tracking and billing time (Module 9a).
--
-- People log hours against a project: billable or not, at a rate (the project's hourly rate unless one is given).
-- Billing time puts the billable hours on an invoice for the project's customer; each entry then names that invoice.
-- Rules here:
--   1. An entry is for a project of its company, worked by one of its members, entered by the person signed in.
--   2. A billed entry (its invoice not void) never changes and is never removed; voiding the invoice frees its hours to
--      be billed again. Only billable hours are billed, on an invoice of the same company.

ALTER TABLE projects ADD COLUMN hourly_rate numeric(20,2) CHECK (hourly_rate >= 0);

CREATE TABLE time_entries (
    id         uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id uuid          NOT NULL REFERENCES companies (id),
    project_id uuid          NOT NULL REFERENCES dimensions (id),
    user_id    uuid          NOT NULL REFERENCES users (id),
    work_date  date          NOT NULL,
    hours      numeric(5,2)  NOT NULL CHECK (hours > 0 AND hours <= 24),
    billable   boolean       NOT NULL DEFAULT true,
    rate       numeric(20,2) CHECK (rate >= 0),
    note       text          CHECK (length(note) <= 500),
    invoice_id uuid          REFERENCES documents (id),
    created_by uuid          NOT NULL REFERENCES users (id),
    created_at timestamptz   NOT NULL DEFAULT now(),
    updated_at timestamptz   NOT NULL DEFAULT now(),
    CHECK (billable OR invoice_id IS NULL)
);
CREATE INDEX time_entries_project_idx ON time_entries (company_id, project_id, work_date);

-- True when the entry is on an invoice that still stands (not void).
CREATE FUNCTION time_entry_billed(p_company uuid, p_invoice uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT p_invoice IS NOT NULL AND EXISTS (SELECT 1 FROM documents WHERE company_id = p_company AND id = p_invoice AND status <> 'void')
$$;
REVOKE ALL ON FUNCTION time_entry_billed(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION time_entry_billed(uuid, uuid) TO {{app_role}};

CREATE FUNCTION time_entries_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF time_entry_billed(OLD.company_id, OLD.invoice_id) THEN
            RAISE EXCEPTION 'These hours are billed: void the invoice to free them' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'INSERT' AND app_user_id() IS NOT NULL AND NEW.created_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'Hours are entered by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.company_id, NEW.created_by, NEW.created_at) IS DISTINCT FROM (OLD.company_id, OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'An entry keeps its company and who entered it' USING ERRCODE = 'check_violation';
        END IF;
        IF time_entry_billed(OLD.company_id, OLD.invoice_id) THEN
            RAISE EXCEPTION 'These hours are billed: void the invoice to change them' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM dimensions WHERE id = NEW.project_id AND company_id = NEW.company_id AND kind = 'project') THEN
        RAISE EXCEPTION 'Hours are logged against a project of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = NEW.company_id AND user_id = NEW.user_id AND status = 'active') THEN
        RAISE EXCEPTION 'Hours are worked by a member of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.invoice_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents WHERE company_id = NEW.company_id AND id = NEW.invoice_id AND type = 'invoice') THEN
        RAISE EXCEPTION 'Hours are billed on an invoice of the company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER time_entries_valid BEFORE INSERT OR UPDATE OR DELETE ON time_entries
    FOR EACH ROW EXECUTE FUNCTION time_entries_valid();

ALTER TABLE time_entries ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON time_entries
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON time_entries FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON time_entries TO {{app_role}};

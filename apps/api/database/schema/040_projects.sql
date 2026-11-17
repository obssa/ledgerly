-- Ledgerly core schema, part 40: projects and job costing (Module 9a).
--
-- A project is a dimension of kind 'project' (014) with its details: the customer it is for, its status, dates, how it
-- is billed, its contract value and its budget. Job costing tags each line of an invoice, credit note, bill, supplier
-- credit or expense with a project; posting carries the tag to the ledger lines (journal_lines.project_id), so the
-- project's profit is read from the ledger like everything else. Rules here:
--   1. A project's details belong to a project dimension of the same company; its customer is one of its customers.
--   2. A document line names a project of its own company, or none.

CREATE TABLE projects (
    dimension_id   uuid          PRIMARY KEY REFERENCES dimensions (id),
    company_id     uuid          NOT NULL REFERENCES companies (id),
    customer_id    uuid          REFERENCES contacts (id),
    status         text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'on_hold', 'completed', 'cancelled')),
    starts_on      date,
    ends_on        date,
    billing        text          NOT NULL DEFAULT 'fixed_fee' CHECK (billing IN ('fixed_fee', 'time_and_materials', 'non_billable')),
    contract_value numeric(20,2) CHECK (contract_value >= 0),
    budget_income  numeric(20,2) CHECK (budget_income >= 0),
    budget_cost    numeric(20,2) CHECK (budget_cost >= 0),
    notes          text          CHECK (length(notes) <= 2000),
    created_at     timestamptz   NOT NULL DEFAULT now(),
    updated_at     timestamptz   NOT NULL DEFAULT now(),
    CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
);
CREATE INDEX projects_company_idx ON projects (company_id, status);

CREATE FUNCTION projects_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.dimension_id, NEW.company_id) IS DISTINCT FROM (OLD.dimension_id, OLD.company_id) THEN
        RAISE EXCEPTION 'A project stays the project and company it was made for' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM dimensions WHERE id = NEW.dimension_id AND company_id = NEW.company_id AND kind = 'project') THEN
        RAISE EXCEPTION 'Project details belong to a project of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.customer_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contacts WHERE id = NEW.customer_id AND company_id = NEW.company_id AND is_customer) THEN
        RAISE EXCEPTION 'A project is for one of the company''s customers' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER projects_valid BEFORE INSERT OR UPDATE ON projects
    FOR EACH ROW EXECUTE FUNCTION projects_valid();

ALTER TABLE document_lines ADD COLUMN project_id uuid;

CREATE FUNCTION document_lines_project() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT dimension_belongs(NEW.company_id, 'project', NEW.project_id) THEN
        RAISE EXCEPTION 'A document line names a project that is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER document_lines_project BEFORE INSERT OR UPDATE OF project_id ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_project();

ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON projects
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON projects FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON projects TO {{app_role}};

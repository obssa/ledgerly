-- Evenbooks core schema, part 49: project milestones and progress billing (Module 9a).
--
-- A fixed-fee project is billed in steps: a milestone is a part of the contract (an amount, often a share of the
-- contract value) with a name and a due date, billed on an invoice to the project's customer when it is reached.
-- Rules here:
--   1. A milestone belongs to a project of the same company.
--   2. It is billed on one invoice of the company, to the project's customer.
--   3. Once billed on an invoice that stands (not void), the milestone is fixed: its name, amount and project stay, it
--      stays on that invoice, and it is not removed. Voiding the invoice opens it again.

CREATE TABLE project_milestones (
    id          uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    project_id  uuid          NOT NULL REFERENCES dimensions (id),
    name        text          NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
    amount      numeric(20,2) NOT NULL CHECK (amount > 0),
    due_on      date,
    position    integer       NOT NULL DEFAULT 0,
    invoice_id  uuid          REFERENCES documents (id),
    created_at  timestamptz   NOT NULL DEFAULT now(),
    updated_at  timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX project_milestones_project ON project_milestones (company_id, project_id, position);

CREATE FUNCTION project_milestones_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    billed boolean := false;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') AND OLD.invoice_id IS NOT NULL THEN
        billed := EXISTS (SELECT 1 FROM documents WHERE id = OLD.invoice_id AND status <> 'void');
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF billed THEN
            RAISE EXCEPTION 'A billed milestone is kept: void its invoice first' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND billed AND (NEW.company_id, NEW.project_id, NEW.name, NEW.amount, NEW.invoice_id)
                                       IS DISTINCT FROM (OLD.company_id, OLD.project_id, OLD.name, OLD.amount, OLD.invoice_id) THEN
        RAISE EXCEPTION 'A billed milestone stays as billed: void its invoice first' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.project_id) IS DISTINCT FROM (OLD.company_id, OLD.project_id) THEN
        RAISE EXCEPTION 'A milestone stays with its project' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM projects WHERE dimension_id = NEW.project_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A milestone belongs to a project of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.invoice_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id)
       AND NOT EXISTS (SELECT 1 FROM documents d JOIN projects p ON p.dimension_id = NEW.project_id AND p.company_id = NEW.company_id
                        WHERE d.id = NEW.invoice_id AND d.company_id = NEW.company_id AND d.type = 'invoice'
                          AND d.status <> 'void' AND d.contact_id = p.customer_id) THEN
        RAISE EXCEPTION 'A milestone is billed on an invoice of the company to the project''s customer' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER project_milestones_valid BEFORE INSERT OR UPDATE OR DELETE ON project_milestones
    FOR EACH ROW EXECUTE FUNCTION project_milestones_valid();

ALTER TABLE project_milestones ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON project_milestones
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON project_milestones FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON project_milestones TO {{app_role}};

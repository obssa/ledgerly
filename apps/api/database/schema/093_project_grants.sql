-- Evenbooks core schema, part 93: grants for NGOs (Module 9a).
--
-- A grant is a project funded by a donor: the award, the donor's reference, and its budget, one line per expense
-- account. A restricted grant is spent only on its budget lines: once it has a budget, an expense tagged with the
-- project on any other account is refused (a reversal is not spending, so it is let through). The donor report reads
-- the ledger by the project dimension, so nothing about what was spent is stored here. Rules:
--   1. A grant is of a project of the company, from a donor who is a contact of the company; it stays its project's.
--   2. A budget line is an expense account of the company, on a grant of the company, once per account.
--   3. A restricted grant with a budget takes expenses only on its budget lines.

CREATE TABLE project_grants (
    project_id  uuid          PRIMARY KEY REFERENCES dimensions (id),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    donor_id    uuid          REFERENCES contacts (id),
    award       numeric(20,2) NOT NULL CHECK (award > 0),
    reference   text          CHECK (length(reference) BETWEEN 1 AND 100),
    restricted  boolean       NOT NULL DEFAULT true,
    created_at  timestamptz   NOT NULL DEFAULT now(),
    updated_at  timestamptz   NOT NULL DEFAULT now()
);

CREATE TABLE grant_budget_lines (
    id          uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    project_id  uuid          NOT NULL REFERENCES project_grants (project_id) ON DELETE CASCADE,
    account_id  uuid          NOT NULL REFERENCES accounts (id),
    amount      numeric(20,2) NOT NULL CHECK (amount >= 0),
    UNIQUE (project_id, account_id)
);
CREATE INDEX grant_budget_lines_company ON grant_budget_lines (company_id, project_id);

CREATE FUNCTION project_grants_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.project_id, NEW.company_id) IS DISTINCT FROM (OLD.project_id, OLD.company_id) THEN
        RAISE EXCEPTION 'A grant stays the project and company it was set up for' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM dimensions WHERE id = NEW.project_id AND company_id = NEW.company_id AND kind = 'project') THEN
        RAISE EXCEPTION 'A grant is of a project of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.donor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.donor_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A grant''s donor is a contact of the company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION project_grants_valid() FROM PUBLIC;
CREATE TRIGGER project_grants_valid BEFORE INSERT OR UPDATE ON project_grants FOR EACH ROW EXECUTE FUNCTION project_grants_valid();

CREATE FUNCTION grant_budget_lines_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM project_grants WHERE project_id = NEW.project_id AND company_id = NEW.company_id)
       OR NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.account_id AND company_id = NEW.company_id AND type = 'expense') THEN
        RAISE EXCEPTION 'A budget line is an expense account of the company, on a grant of the company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION grant_budget_lines_valid() FROM PUBLIC;
CREATE TRIGGER grant_budget_lines_valid BEFORE INSERT OR UPDATE ON grant_budget_lines FOR EACH ROW EXECUTE FUNCTION grant_budget_lines_valid();

-- Rule 3. Only lines naming a project are looked at; a credit (or a reversal) is never spending.
CREATE FUNCTION journal_lines_grant_budget() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    g record;
BEGIN
    IF NEW.project_id IS NULL OR NEW.debit <= 0 THEN
        RETURN NEW;
    END IF;
    SELECT d.code INTO g FROM project_grants p JOIN dimensions d ON d.id = p.project_id
     WHERE p.project_id = NEW.project_id AND p.company_id = NEW.company_id AND p.restricted
       AND EXISTS (SELECT 1 FROM grant_budget_lines b WHERE b.project_id = p.project_id);
    IF NOT FOUND
       OR NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.account_id AND type = 'expense')
       OR EXISTS (SELECT 1 FROM grant_budget_lines WHERE project_id = NEW.project_id AND account_id = NEW.account_id)
       OR EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type = 'reversal') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Grant % is restricted: it is spent only on its budget lines', g.code USING ERRCODE = 'check_violation';
END $$;
REVOKE ALL ON FUNCTION journal_lines_grant_budget() FROM PUBLIC;
CREATE TRIGGER journal_lines_grant_budget BEFORE INSERT ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_lines_grant_budget();

ALTER TABLE project_grants ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON project_grants
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
ALTER TABLE grant_budget_lines ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON grant_budget_lines
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON project_grants, grant_budget_lines FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON project_grants TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON grant_budget_lines TO {{app_role}};

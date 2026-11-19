-- Ledgerly core schema, part 83: employee expense claims (Module 3).
--
-- A member of staff who paid for the company out of their own pocket claims it back. The claim is an ordinary bill
-- payable to them: the company keeps a supplier record for the member (contacts.employee_user_id), and the bill names
-- the member who claimed it (documents.claimed_by). Someone else approves it with the usual bill approval, and it is paid
-- like any bill. Rules:
--   1. A staff payee names a member of the company, once per company.
--   2. A claim is a bill to the claimant's own staff payee.
--   3. The claimant never posts their own claim (the one approval step).

ALTER TABLE contacts ADD COLUMN employee_user_id uuid REFERENCES users (id);
CREATE UNIQUE INDEX contacts_employee_once ON contacts (company_id, employee_user_id) WHERE employee_user_id IS NOT NULL;

ALTER TABLE documents ADD COLUMN claimed_by uuid REFERENCES users (id),
    ADD CONSTRAINT documents_claim_is_bill CHECK (claimed_by IS NULL OR type = 'bill');

-- Rule 1.
CREATE FUNCTION contacts_employee_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.employee_user_id IS NULL OR (TG_OP = 'UPDATE' AND NEW.employee_user_id IS NOT DISTINCT FROM OLD.employee_user_id) THEN
        RETURN NEW;
    END IF;
    IF NOT NEW.is_vendor OR NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = NEW.company_id AND user_id = NEW.employee_user_id) THEN
        RAISE EXCEPTION 'A staff payee is a supplier record for a member of the company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION contacts_employee_valid() FROM PUBLIC;
CREATE TRIGGER contacts_employee_valid BEFORE INSERT OR UPDATE OF employee_user_id, is_vendor ON contacts
    FOR EACH ROW EXECUTE FUNCTION contacts_employee_valid();

-- Rules 2 and 3.
CREATE FUNCTION documents_claim_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.claimed_by IS NULL THEN
        RETURN NEW;
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.claimed_by IS DISTINCT FROM OLD.claimed_by OR NEW.contact_id IS DISTINCT FROM OLD.contact_id)
       AND NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id AND employee_user_id = NEW.claimed_by) THEN
        RAISE EXCEPTION 'An expense claim is a bill to the claimant''s own staff payee' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.status = 'posted' AND OLD.status IS DISTINCT FROM 'posted' AND NEW.claimed_by = app_user_id() THEN
        RAISE EXCEPTION 'An expense claim is approved by someone other than the person who claimed it' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION documents_claim_valid() FROM PUBLIC;
CREATE TRIGGER documents_claim_valid BEFORE INSERT OR UPDATE OF claimed_by, contact_id, status ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_claim_valid();

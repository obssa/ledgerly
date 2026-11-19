-- Module 2: interest on late payment (079). A company may charge its customers interest on invoices paid late, at an
-- annual rate it sets (companies.settings.late_interest: {"rate": "18", "account_id": ...}). Charging it makes an
-- ordinary invoice to the same customer, in the same currency, for the days from the due date (or the end of the last
-- charge) to a date: simple interest on what the invoice still owes, to the income account the company chose. The
-- invoice is a draft until someone approves it, like any other. Rules here:
--   1. An interest invoice names one invoice of the company, for the same customer and currency, that was posted, and
--      a period that starts on or after its due date and ends after it starts.
--   2. Two live interest invoices for the same invoice never cover the same day (a void one frees its days).
--   3. Charges for one invoice are made one at a time (the invoice is locked).

ALTER TABLE documents ADD COLUMN interest_on_document_id uuid REFERENCES documents (id),
    ADD COLUMN interest_from date,
    ADD COLUMN interest_through date,
    ADD CONSTRAINT documents_interest_shape CHECK (
        (interest_on_document_id IS NULL AND interest_from IS NULL AND interest_through IS NULL)
        OR (interest_on_document_id IS NOT NULL AND interest_from IS NOT NULL AND interest_through IS NOT NULL
            AND interest_from < interest_through AND type = 'invoice' AND interest_on_document_id <> id));
CREATE INDEX documents_interest_on ON documents (interest_on_document_id) WHERE interest_on_document_id IS NOT NULL;

-- Rules 1 to 3. Checked when the link or its period is set, and when an interest invoice comes back from void (never).
CREATE FUNCTION documents_interest_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    src record;
BEGIN
    IF NEW.interest_on_document_id IS NULL OR NEW.status = 'void' OR (TG_OP = 'UPDATE'
       AND NEW.interest_on_document_id IS NOT DISTINCT FROM OLD.interest_on_document_id
       AND NEW.interest_from IS NOT DISTINCT FROM OLD.interest_from AND NEW.interest_through IS NOT DISTINCT FROM OLD.interest_through
       AND NEW.contact_id IS NOT DISTINCT FROM OLD.contact_id AND NEW.currency IS NOT DISTINCT FROM OLD.currency) THEN
        RETURN NEW;
    END IF;
    SELECT company_id, type, status, contact_id, currency, due_date, issue_date INTO src
      FROM documents WHERE id = NEW.interest_on_document_id FOR UPDATE;
    IF NOT FOUND OR src.company_id <> NEW.company_id OR src.type <> 'invoice' OR src.contact_id <> NEW.contact_id
       OR src.currency <> NEW.currency OR src.status NOT IN ('posted', 'part_paid', 'paid') THEN
        RAISE EXCEPTION 'Interest is charged on a posted invoice of the company, to its customer in its currency' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.interest_from < coalesce(src.due_date, src.issue_date) THEN
        RAISE EXCEPTION 'Interest runs from the day the invoice fell due' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM documents d WHERE d.interest_on_document_id = NEW.interest_on_document_id AND d.id <> NEW.id
                 AND d.status <> 'void' AND d.interest_from < NEW.interest_through AND NEW.interest_from < d.interest_through) THEN
        RAISE EXCEPTION 'Interest for these days is already charged on this invoice' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION documents_interest_valid() FROM PUBLIC;
CREATE TRIGGER documents_interest_valid BEFORE INSERT OR UPDATE OF interest_on_document_id, interest_from, interest_through, contact_id, currency, status ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_interest_valid();

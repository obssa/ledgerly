-- Evenbooks core schema, part 17: credit notes (Module 2).
--
-- A credit note corrects one posted invoice (a return, a price correction) and must name it. It is in the invoice's
-- customer and currency, and the credit notes of one invoice never add up to more than the invoice.
--
-- A posted credit note's amount_due is the credit still unused. Applying it to the invoice it corrects (or another
-- open invoice of the same customer and currency) lowers both amounts due, like a payment allocation (006): the
-- statuses follow. What is not applied stays as the customer's credit, to apply later or refund.

ALTER TABLE documents ADD COLUMN original_document_id uuid REFERENCES documents (id);
ALTER TABLE documents ADD CONSTRAINT documents_credit_note_names_its_invoice
    CHECK (type <> 'credit_note' OR original_document_id IS NOT NULL);
ALTER TABLE documents ADD CONSTRAINT documents_only_credit_notes_name_an_invoice
    CHECK (type IN ('credit_note', 'vendor_credit') OR original_document_id IS NULL);

-- SECURITY DEFINER: the totals of an invoice's credit notes must count every one, whatever the caller can see.
CREATE FUNCTION credit_notes_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    inv record;
    credited numeric;
BEGIN
    IF NEW.type <> 'credit_note' THEN
        RETURN NEW;
    END IF;
    SELECT company_id, contact_id, currency, type, status, total INTO inv FROM documents WHERE id = NEW.original_document_id;
    IF inv.company_id IS DISTINCT FROM NEW.company_id OR inv.type <> 'invoice' OR inv.contact_id IS DISTINCT FROM NEW.contact_id
       OR inv.currency <> NEW.currency THEN
        RAISE EXCEPTION 'A credit note corrects an invoice of the same company, customer and currency' USING ERRCODE = 'check_violation';
    END IF;
    IF inv.status NOT IN ('posted', 'part_paid', 'paid') THEN
        RAISE EXCEPTION 'Only a posted invoice can be credited' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IN ('posted', 'part_paid', 'paid') THEN
        SELECT coalesce(sum(total), 0) INTO credited FROM documents
         WHERE original_document_id = NEW.original_document_id AND type = 'credit_note' AND id <> NEW.id
           AND status IN ('posted', 'part_paid', 'paid');
        IF credited + NEW.total > inv.total THEN
            RAISE EXCEPTION 'Credit notes of an invoice cannot add up to more than the invoice' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION credit_notes_valid() FROM PUBLIC;
CREATE TRIGGER documents_credit_notes_valid BEFORE INSERT OR UPDATE OF original_document_id, contact_id, currency, total, status ON documents
    FOR EACH ROW EXECUTE FUNCTION credit_notes_valid();

CREATE TABLE credit_applications (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id     uuid          NOT NULL REFERENCES companies (id),
    credit_note_id uuid          NOT NULL REFERENCES documents (id),
    invoice_id     uuid          NOT NULL REFERENCES documents (id),
    amount         numeric(20,2) NOT NULL CHECK (amount > 0),
    created_by     uuid          REFERENCES users (id),
    created_at     timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (credit_note_id, invoice_id)
);

-- Both documents locked in a fixed order (by id) so two applications never deadlock; both amounts due move.
CREATE FUNCTION credit_applications_apply() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    cn record;
    inv record;
BEGIN
    PERFORM 1 FROM documents WHERE id IN (NEW.credit_note_id, NEW.invoice_id) ORDER BY id FOR UPDATE;
    SELECT company_id, contact_id, currency, type, status, amount_due INTO cn FROM documents WHERE id = NEW.credit_note_id;
    SELECT company_id, contact_id, currency, type, status, amount_due INTO inv FROM documents WHERE id = NEW.invoice_id;
    IF cn.company_id IS DISTINCT FROM NEW.company_id OR inv.company_id IS DISTINCT FROM NEW.company_id
       OR cn.type <> 'credit_note' OR inv.type <> 'invoice'
       OR cn.contact_id IS DISTINCT FROM inv.contact_id OR cn.currency <> inv.currency THEN
        RAISE EXCEPTION 'A credit note applies to an invoice of the same company, customer and currency' USING ERRCODE = 'check_violation';
    END IF;
    IF cn.status NOT IN ('posted', 'part_paid') OR inv.status NOT IN ('posted', 'part_paid') THEN
        RAISE EXCEPTION 'Both the credit note and the invoice must be posted and still open' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > cn.amount_due OR NEW.amount > inv.amount_due THEN
        RAISE EXCEPTION 'Applied % is more than the credit left (%) or the invoice still owes (%)', NEW.amount, cn.amount_due, inv.amount_due
            USING ERRCODE = 'check_violation';
    END IF;
    UPDATE documents SET amount_due = amount_due - NEW.amount, updated_at = now() WHERE id IN (NEW.credit_note_id, NEW.invoice_id);
    RETURN NEW;
END $$;
CREATE TRIGGER credit_applications_apply BEFORE INSERT ON credit_applications FOR EACH ROW EXECUTE FUNCTION credit_applications_apply();

ALTER TABLE credit_applications ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON credit_applications
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
-- An application is history: never changed or removed.
GRANT SELECT, INSERT ON credit_applications TO {{app_role}};

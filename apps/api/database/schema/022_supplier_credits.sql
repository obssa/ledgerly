-- Ledgerly core schema, part 22: supplier credits (Module 3).
--
-- A supplier credit (documents.type 'vendor_credit') is what a supplier gives back on one posted bill: goods returned,
-- a price corrected. It mirrors a customer credit note (017): it names the bill, is in the bill's supplier and
-- currency, and the credits of one bill never add up to more than the bill. Applying it lowers what the bill still
-- costs and the credit left, through the same credit_applications table (its columns hold the credit and the
-- document it settles: an invoice for a credit note, a bill for a supplier credit).

ALTER TABLE documents ADD CONSTRAINT documents_vendor_credit_names_its_bill
    CHECK (type <> 'vendor_credit' OR original_document_id IS NOT NULL);

CREATE OR REPLACE FUNCTION credit_notes_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    doc record;
    corrects text;
    credited numeric;
BEGIN
    IF NEW.type NOT IN ('credit_note', 'vendor_credit') THEN
        RETURN NEW;
    END IF;
    corrects := CASE NEW.type WHEN 'credit_note' THEN 'invoice' ELSE 'bill' END;
    SELECT company_id, contact_id, currency, type, status, total INTO doc FROM documents WHERE id = NEW.original_document_id;
    IF doc.company_id IS DISTINCT FROM NEW.company_id OR doc.type IS DISTINCT FROM corrects OR doc.contact_id IS DISTINCT FROM NEW.contact_id
       OR doc.currency <> NEW.currency THEN
        RAISE EXCEPTION 'A credit note corrects an invoice, a supplier credit a bill, of the same company, contact and currency'
            USING ERRCODE = 'check_violation';
    END IF;
    IF doc.status NOT IN ('posted', 'part_paid', 'paid') THEN
        RAISE EXCEPTION 'Only a posted % can be credited', corrects USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IN ('posted', 'part_paid', 'paid') THEN
        SELECT coalesce(sum(total), 0) INTO credited FROM documents
         WHERE original_document_id = NEW.original_document_id AND type = NEW.type AND id <> NEW.id
           AND status IN ('posted', 'part_paid', 'paid');
        IF credited + NEW.total > doc.total THEN
            RAISE EXCEPTION 'The credits of one % cannot add up to more than it', corrects USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION credit_applications_apply() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    cn record;
    inv record;
BEGIN
    PERFORM 1 FROM documents WHERE id IN (NEW.credit_note_id, NEW.invoice_id) ORDER BY id FOR UPDATE;
    SELECT company_id, contact_id, currency, type, status, amount_due INTO cn FROM documents WHERE id = NEW.credit_note_id;
    SELECT company_id, contact_id, currency, type, status, amount_due INTO inv FROM documents WHERE id = NEW.invoice_id;
    IF cn.company_id IS DISTINCT FROM NEW.company_id OR inv.company_id IS DISTINCT FROM NEW.company_id
       OR NOT ((cn.type = 'credit_note' AND inv.type = 'invoice') OR (cn.type = 'vendor_credit' AND inv.type = 'bill'))
       OR cn.contact_id IS DISTINCT FROM inv.contact_id OR cn.currency <> inv.currency THEN
        RAISE EXCEPTION 'A credit applies to an invoice (credit note) or a bill (supplier credit) of the same company, contact and currency'
            USING ERRCODE = 'check_violation';
    END IF;
    IF cn.status NOT IN ('posted', 'part_paid') OR inv.status NOT IN ('posted', 'part_paid') THEN
        RAISE EXCEPTION 'Both the credit and the document it settles must be posted and still open' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > cn.amount_due OR NEW.amount > inv.amount_due THEN
        RAISE EXCEPTION 'Applied % is more than the credit left (%) or what is still due (%)', NEW.amount, cn.amount_due, inv.amount_due
            USING ERRCODE = 'check_violation';
    END IF;
    UPDATE documents SET amount_due = amount_due - NEW.amount, updated_at = now() WHERE id IN (NEW.credit_note_id, NEW.invoice_id);
    RETURN NEW;
END $$;

-- Ledgerly core schema, part 82: cash sales sent to the tax authority, and credited (Module 2).
--
-- A cash sale (070) is now registered with the tax authority like an invoice. Once registered it is no longer voided:
-- it is corrected with a credit note, as an invoice is. A cash sale was paid on the spot, so its credit note owes the
-- customer the amount: it stays as their credit, to refund or to apply to an invoice.
--
-- Rule 017/022, widened by one case: a credit note corrects an invoice or a cash sale, a supplier credit a bill, of the
-- same company, contact and currency, once posted; the credits of one never add up to more than it. Everything else in
-- the rule is as 022 left it.

CREATE OR REPLACE FUNCTION credit_notes_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    doc record;
    corrects text[];
    credited numeric;
BEGIN
    IF NEW.type NOT IN ('credit_note', 'vendor_credit') THEN
        RETURN NEW;
    END IF;
    corrects := CASE NEW.type WHEN 'credit_note' THEN ARRAY['invoice', 'sales_receipt'] ELSE ARRAY['bill'] END;
    SELECT company_id, contact_id, currency, type, status, total INTO doc FROM documents WHERE id = NEW.original_document_id;
    IF doc.company_id IS DISTINCT FROM NEW.company_id OR NOT coalesce(doc.type = ANY (corrects), false) OR doc.contact_id IS DISTINCT FROM NEW.contact_id
       OR doc.currency <> NEW.currency THEN
        RAISE EXCEPTION 'A credit note corrects an invoice or a cash sale, a supplier credit a bill, of the same company, contact and currency'
            USING ERRCODE = 'check_violation';
    END IF;
    IF doc.status NOT IN ('posted', 'part_paid', 'paid') THEN
        RAISE EXCEPTION 'Only a posted % can be credited', doc.type USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IN ('posted', 'part_paid', 'paid') THEN
        SELECT coalesce(sum(total), 0) INTO credited FROM documents
         WHERE original_document_id = NEW.original_document_id AND type = NEW.type AND id <> NEW.id
           AND status IN ('posted', 'part_paid', 'paid');
        IF credited + NEW.total > doc.total THEN
            RAISE EXCEPTION 'The credits of one % cannot add up to more than it', doc.type USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

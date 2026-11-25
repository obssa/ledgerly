-- Evenbooks core schema, part 44: the salesperson on a sales document (Module 2, for Module 10's sales by salesperson).
--
-- An invoice, credit note, cash sale, quote or sales order may name the member of the company who made the sale.
-- Rules here:
--   1. Only sales documents name a salesperson; bills, supplier credits and purchase orders never do.
--   2. A salesperson named or changed is an active member of the document's company. A document keeps the
--      salesperson it had when that person leaves the company, so past sales stay theirs, and a credit note may
--      name its invoice's salesperson.

ALTER TABLE documents ADD COLUMN salesperson_id uuid REFERENCES users (id);
CREATE INDEX documents_salesperson_idx ON documents (company_id, salesperson_id) WHERE salesperson_id IS NOT NULL;

CREATE FUNCTION documents_salesperson_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.salesperson_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.type NOT IN ('invoice', 'credit_note', 'sales_receipt', 'quote', 'sales_order') THEN
        RAISE EXCEPTION 'Only a sales document names a salesperson' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.salesperson_id = OLD.salesperson_id AND NEW.company_id = OLD.company_id THEN
        RETURN NEW;   -- unchanged: kept even after the person left
    END IF;
    IF NEW.type = 'credit_note' AND NEW.salesperson_id = (
        SELECT salesperson_id FROM documents WHERE id = NEW.original_document_id AND company_id = NEW.company_id) THEN
        RETURN NEW;   -- a credit note takes back the sale of whoever made it, even after they left
    END IF;
    IF NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = NEW.company_id AND user_id = NEW.salesperson_id AND status = 'active') THEN
        RAISE EXCEPTION 'A salesperson is an active member of the company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_salesperson_valid BEFORE INSERT OR UPDATE OF salesperson_id, company_id, type ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_salesperson_valid();

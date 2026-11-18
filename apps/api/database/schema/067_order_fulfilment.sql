-- Ledgerly core schema, part 67: sales orders delivered in parts (Module 2).
--
-- A confirmed sales order is invoiced as its goods go out, in as many invoices as it takes: each invoice line made
-- from it names the order line it delivers (source_line_id). What is still to deliver is the back-order, and what
-- the open orders still hold of a stock item is reserved for them. The order stays confirmed (accepted) until all of
-- it is delivered or it is closed; it is then converted.
--
-- The rules here:
--   1. A quote still makes one live document; an order makes any number of invoices, of the same customer, while it
--      is confirmed. (056's unique index is replaced by the check below, which keeps its error code.)
--   2. An invoice line names a line of the order its invoice was made from, for the same item, with a positive
--      quantity, and the live invoices never deliver more of an order line than was ordered.
--   3. An order is not cancelled once a live invoice was made from it: it is closed instead.

DROP INDEX documents_one_per_source;
CREATE INDEX documents_source ON documents (company_id, source_document_id) WHERE source_document_id IS NOT NULL;

ALTER TABLE document_lines ADD COLUMN source_line_id uuid REFERENCES document_lines (id);
CREATE INDEX document_lines_source ON document_lines (source_line_id) WHERE source_line_id IS NOT NULL;

CREATE OR REPLACE FUNCTION documents_source_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    src record;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.source_document_id IS DISTINCT FROM OLD.source_document_id THEN
        -- Set once, on a draft (the conversion saves the draft, then names its source); never changed or removed.
        IF OLD.source_document_id IS NOT NULL OR OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'The quote or order a document was made from does not change' USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    IF NEW.source_document_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.source_document_id IS DISTINCT FROM OLD.source_document_id) THEN
        -- Locked, so two conversions of one quote run one after the other and the second sees the first.
        SELECT type, contact_id, status INTO src
          FROM documents WHERE id = NEW.source_document_id AND company_id = NEW.company_id FOR UPDATE;
        IF NOT FOUND OR NOT (src.type = 'quote' OR (src.type = 'sales_order' AND NEW.type = 'invoice')) THEN
            RAISE EXCEPTION 'A sales order is made from a quote, an invoice from a quote or a sales order, of the same company'
                USING ERRCODE = 'check_violation';
        END IF;
        IF src.contact_id <> NEW.contact_id THEN
            RAISE EXCEPTION 'A document made from a quote or order is for the same customer' USING ERRCODE = 'check_violation';
        END IF;
        IF src.status <> 'accepted' THEN
            RAISE EXCEPTION 'Only an accepted quote or a confirmed order is converted' USING ERRCODE = 'check_violation';
        END IF;
        IF src.type = 'quote' AND EXISTS (SELECT 1 FROM documents d
                                           WHERE d.company_id = NEW.company_id AND d.source_document_id = NEW.source_document_id
                                             AND d.id <> NEW.id AND d.status <> 'void') THEN
            RAISE EXCEPTION 'A quote makes one document' USING ERRCODE = 'unique_violation';
        END IF;
    END IF;

    -- A quote or order is converted only once the document made from it exists.
    IF TG_OP = 'UPDATE' AND NEW.status = 'converted' AND OLD.status <> 'converted'
       AND NOT EXISTS (SELECT 1 FROM documents d
                        WHERE d.company_id = NEW.company_id AND d.source_document_id = NEW.id AND d.status <> 'void') THEN
        RAISE EXCEPTION 'A quote or order is converted by making the document from it' USING ERRCODE = 'check_violation';
    END IF;

    -- An order partly delivered is closed, not cancelled.
    IF TG_OP = 'UPDATE' AND NEW.type = 'sales_order' AND NEW.status = 'void' AND OLD.status <> 'void'
       AND EXISTS (SELECT 1 FROM documents d
                    WHERE d.company_id = NEW.company_id AND d.source_document_id = NEW.id AND d.status <> 'void') THEN
        RAISE EXCEPTION 'A sales order with invoices made from it is closed, not cancelled' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE FUNCTION document_lines_source_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    ordered record;
    made_from uuid;
    delivered numeric;
BEGIN
    IF NEW.source_line_id IS NULL THEN
        RETURN NEW;
    END IF;
    -- The order line, locked: two invoices delivering it at once are counted one after the other.
    SELECT l.document_id, l.item_id, l.quantity INTO ordered
      FROM document_lines l JOIN documents o ON o.id = l.document_id
     WHERE l.id = NEW.source_line_id AND l.company_id = NEW.company_id AND o.type = 'sales_order'
       FOR UPDATE OF l;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'An invoice line delivers a line of the order its invoice was made from' USING ERRCODE = 'check_violation';
    END IF;
    SELECT source_document_id INTO made_from FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id;
    IF made_from IS DISTINCT FROM ordered.document_id THEN
        RAISE EXCEPTION 'An invoice line delivers a line of the order its invoice was made from' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.item_id IS DISTINCT FROM ordered.item_id OR NEW.quantity <= 0 THEN
        RAISE EXCEPTION 'An invoice line delivers the item of its order line, a positive quantity of it' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(l.quantity), 0) INTO delivered
      FROM document_lines l JOIN documents d ON d.id = l.document_id
     WHERE l.source_line_id = NEW.source_line_id AND l.company_id = NEW.company_id
       AND l.id <> NEW.id AND d.status <> 'void';
    IF delivered + NEW.quantity > ordered.quantity THEN
        RAISE EXCEPTION 'More would be delivered than was ordered (% ordered, % already delivered)', ordered.quantity, delivered
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER document_lines_source_valid BEFORE INSERT OR UPDATE OF source_line_id, quantity, item_id ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_source_valid();

-- Ledgerly core schema, part 68: purchase orders (Module 3).
--
-- A purchase order is what the company asked a supplier for: drafted, issued (accepted in the quote machine of 006,
-- which takes its number), billed as the supplier's invoices come in, in as many bills as it takes, and converted
-- once all of it is billed or it is closed. Like a quote or a sales order it posts nothing and owes nothing.
--
-- The rules of 056 and 067, widened to purchases:
--   1. A bill may name the purchase order it was made from: an issued order of the same company and supplier.
--   2. A bill line names a line of that order, for the same item, with a positive quantity, and the live bills never
--      bill more of an order line than was ordered.
--   3. An order partly billed is closed, not cancelled.

ALTER TABLE documents DROP CONSTRAINT documents_source_for_sales;
ALTER TABLE documents ADD CONSTRAINT documents_source_for_sales
    CHECK (source_document_id IS NULL OR type IN ('sales_order', 'invoice', 'bill'));

ALTER TABLE documents DROP CONSTRAINT documents_quotes_owe_nothing;
ALTER TABLE documents ADD CONSTRAINT documents_quotes_owe_nothing
    CHECK (type NOT IN ('quote', 'sales_order', 'purchase_order') OR amount_due = 0);

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
        IF NOT FOUND OR NOT ((src.type = 'quote' AND NEW.type IN ('sales_order', 'invoice'))
                             OR (src.type = 'sales_order' AND NEW.type = 'invoice')
                             OR (src.type = 'purchase_order' AND NEW.type = 'bill')) THEN
            RAISE EXCEPTION 'A sales order is made from a quote, an invoice from a quote or a sales order, a bill from a purchase order, of the same company'
                USING ERRCODE = 'check_violation';
        END IF;
        IF src.contact_id <> NEW.contact_id THEN
            RAISE EXCEPTION 'A document made from a quote or order is for the same customer or supplier' USING ERRCODE = 'check_violation';
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

    -- An order partly delivered or billed is closed, not cancelled.
    IF TG_OP = 'UPDATE' AND NEW.type IN ('sales_order', 'purchase_order') AND NEW.status = 'void' AND OLD.status <> 'void'
       AND EXISTS (SELECT 1 FROM documents d
                    WHERE d.company_id = NEW.company_id AND d.source_document_id = NEW.id AND d.status <> 'void') THEN
        RAISE EXCEPTION 'An order with documents made from it is closed, not cancelled' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION document_lines_source_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    ordered record;
    made_from uuid;
    delivered numeric;
BEGIN
    IF NEW.source_line_id IS NULL THEN
        RETURN NEW;
    END IF;
    -- The order line, locked: two documents delivering or billing it at once are counted one after the other.
    SELECT l.document_id, l.item_id, l.quantity INTO ordered
      FROM document_lines l JOIN documents o ON o.id = l.document_id
     WHERE l.id = NEW.source_line_id AND l.company_id = NEW.company_id AND o.type IN ('sales_order', 'purchase_order')
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

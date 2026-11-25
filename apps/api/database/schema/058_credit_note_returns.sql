-- Evenbooks core schema, part 58: goods returned on a credit note (Module 4).
--
-- A credit note can say the goods came back (restock). Its stock lines then come back into the warehouse at the cost
-- they left with on the invoice (sale_return moves naming the credit note), and its entry takes that cost off cost of
-- goods sold back onto inventory. Voiding it sends them out again (sale moves naming the credit note).
--
-- Rule: what comes back on the credit notes of an invoice is never more, item by item, than the invoice sold. The
-- invoice row is locked so two credit notes cannot both pass the check.

ALTER TABLE documents ADD COLUMN restock boolean NOT NULL DEFAULT false;
ALTER TABLE documents ADD CONSTRAINT documents_restock_on_credit_notes CHECK (NOT restock OR type = 'credit_note');

CREATE FUNCTION stock_moves_return_within_sale() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    credit record;
    sold   numeric;
    back   numeric;
BEGIN
    IF NEW.kind <> 'sale_return' OR NEW.document_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT type, original_document_id INTO credit FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id;
    IF NOT FOUND OR credit.type <> 'credit_note' THEN
        RETURN NEW;   -- a voided invoice's goods coming back name the invoice itself
    END IF;
    PERFORM 1 FROM documents WHERE id = credit.original_document_id AND company_id = NEW.company_id FOR UPDATE;

    SELECT coalesce(-sum(quantity), 0) INTO sold
      FROM stock_moves WHERE company_id = NEW.company_id AND document_id = credit.original_document_id AND item_id = NEW.item_id AND kind = 'sale';
    -- Back so far on the invoice's credit notes, less what went out again when one of them was voided.
    SELECT coalesce(sum(m.quantity), 0) INTO back
      FROM stock_moves m JOIN documents c ON c.id = m.document_id AND c.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND c.type = 'credit_note' AND c.original_document_id = credit.original_document_id
       AND m.item_id = NEW.item_id AND m.kind IN ('sale_return', 'sale');
    IF back + NEW.quantity > sold THEN
        RAISE EXCEPTION 'More of an item comes back than its invoice sold (% sold, % back already)', sold, back
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER stock_moves_return_within_sale BEFORE INSERT ON stock_moves
    FOR EACH ROW EXECUTE FUNCTION stock_moves_return_within_sale();

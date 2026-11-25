-- Evenbooks core schema, part 59: goods returned to a supplier on a supplier credit (Module 4).
--
-- A supplier credit can say the goods went back (restock). Its stock lines then leave the warehouse the bill's goods
-- came into (purchase_return moves naming the supplier credit) at the weighted average; the credit's entry takes the
-- line amounts off inventory, and any difference from the stock's value goes to inventory adjustment. Voiding it
-- brings them back in at the cost they left with (purchase moves naming the supplier credit).
--
-- Rule: what goes back on a bill's supplier credits is never more, item by item, than the bill brought in. The bill
-- row is locked so two credits cannot both pass the check.

ALTER TABLE documents DROP CONSTRAINT documents_restock_on_credit_notes;
ALTER TABLE documents ADD CONSTRAINT documents_restock_on_credits CHECK (NOT restock OR type IN ('credit_note', 'vendor_credit'));

CREATE FUNCTION stock_moves_return_within_purchase() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    credit   record;
    received numeric;
    gone     numeric;
BEGIN
    IF NEW.kind <> 'purchase_return' OR NEW.document_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT type, original_document_id INTO credit FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id;
    IF NOT FOUND OR credit.type <> 'vendor_credit' THEN
        RETURN NEW;   -- a voided bill's goods going out name the bill itself
    END IF;
    PERFORM 1 FROM documents WHERE id = credit.original_document_id AND company_id = NEW.company_id FOR UPDATE;

    SELECT coalesce(sum(quantity), 0) INTO received
      FROM stock_moves WHERE company_id = NEW.company_id AND document_id = credit.original_document_id AND item_id = NEW.item_id AND kind = 'purchase';
    -- Gone back so far on the bill's supplier credits, less what came in again when one of them was voided.
    SELECT coalesce(-sum(m.quantity), 0) INTO gone
      FROM stock_moves m JOIN documents c ON c.id = m.document_id AND c.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND c.type = 'vendor_credit' AND c.original_document_id = credit.original_document_id
       AND m.item_id = NEW.item_id AND m.kind IN ('purchase_return', 'purchase');
    IF gone - NEW.quantity > received THEN
        RAISE EXCEPTION 'More of an item goes back than its bill brought in (% received, % back already)', received, gone
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER stock_moves_return_within_purchase BEFORE INSERT ON stock_moves
    FOR EACH ROW EXECUTE FUNCTION stock_moves_return_within_purchase();

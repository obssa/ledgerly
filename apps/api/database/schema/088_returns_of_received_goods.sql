-- Evenbooks core schema, part 88: supplier credits send back goods that came in on goods received notes (Module 3).
--
-- A bill made from a purchase order may bill goods that came in on goods received notes (069): the notes moved the
-- stock, the bill moved none. A supplier credit for that bill can now send those goods back, as for goods the bill
-- brought in itself (059): out of the warehouse at today's average cost, the difference to inventory adjustment.
--
-- Rule 059, widened to count those goods: what goes back on the supplier credits of a bill is never more, item by item,
-- than the bill brought in, where it brought in what it took into stock itself and what its matches billed from the
-- notes (each match in its order line's unit, counted in the item's own unit). The bill row is still locked first.

CREATE OR REPLACE FUNCTION stock_moves_return_within_purchase() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    credit   record;
    received numeric;
    billed   numeric;
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
    -- 088: what the bill billed from goods received notes, in the item's own unit.
    SELECT coalesce(sum(m.quantity * coalesce(ol.unit_factor, 1)), 0) INTO billed
      FROM bill_receipt_matches m
      JOIN document_lines bl ON bl.id = m.bill_line_id AND bl.company_id = m.company_id
      JOIN document_lines ol ON ol.id = m.order_line_id AND ol.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND m.bill_id = credit.original_document_id AND bl.item_id = NEW.item_id;
    -- Gone back so far on the bill's supplier credits, less what came in again when one of them was voided.
    SELECT coalesce(-sum(m.quantity), 0) INTO gone
      FROM stock_moves m JOIN documents c ON c.id = m.document_id AND c.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND c.type = 'vendor_credit' AND c.original_document_id = credit.original_document_id
       AND m.item_id = NEW.item_id AND m.kind IN ('purchase_return', 'purchase');
    IF gone - NEW.quantity > received + billed THEN
        RAISE EXCEPTION 'More of an item goes back than its bill brought in (% received, % back already)', received + billed, gone
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

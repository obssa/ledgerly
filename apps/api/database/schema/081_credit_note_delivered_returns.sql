-- Evenbooks core schema, part 81: credit notes bring back goods delivered on notes (Module 2).
--
-- An invoice made from a sales order may bill goods that left on delivery notes (071): the invoice moved no stock, the
-- notes did. A credit note for that invoice can now say the goods came back: they return at the cost they left the
-- notes with, as for goods the invoice took out itself (058).
--
-- Rule 058, widened to count those goods: what comes back on the credit notes of an invoice is never more, item by item,
-- than the invoice sold, where it sold what it took out of stock itself and what its matches billed from the notes (each
-- match in its order line's unit, counted in the item's own unit). The invoice row is still locked first.

CREATE OR REPLACE FUNCTION stock_moves_return_within_sale() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    credit record;
    sold   numeric;
    billed numeric;
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
    -- 081: what the invoice billed from delivery notes, in the item's own unit.
    SELECT coalesce(sum(m.quantity * coalesce(ol.unit_factor, 1)), 0) INTO billed
      FROM invoice_delivery_matches m
      JOIN document_lines il ON il.id = m.invoice_line_id AND il.company_id = m.company_id
      JOIN document_lines ol ON ol.id = m.order_line_id AND ol.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND m.invoice_id = credit.original_document_id AND il.item_id = NEW.item_id;
    -- Back so far on the invoice's credit notes, less what went out again when one of them was voided.
    SELECT coalesce(sum(m.quantity), 0) INTO back
      FROM stock_moves m JOIN documents c ON c.id = m.document_id AND c.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND c.type = 'credit_note' AND c.original_document_id = credit.original_document_id
       AND m.item_id = NEW.item_id AND m.kind IN ('sale_return', 'sale');
    IF back + NEW.quantity > sold + billed THEN
        RAISE EXCEPTION 'More of an item comes back than its invoice sold (% sold, % back already)', sold + billed, back
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

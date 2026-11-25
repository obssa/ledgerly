-- Evenbooks core schema, part 97: bundles delivered on delivery notes (Module 4).
--
-- A bundle on a confirmed sales order (073) can now leave on a delivery note (071) as a stock item does: the note's
-- line names the bundle and its order line, and the bundle's stock parts leave the warehouse that day, each its
-- quantity per bundle times the bundles delivered (in the part's own unit), at their cost. The order's invoice then
-- bills the bundle without moving its parts again, and a credit note for that invoice can bring them back at the cost
-- they left with. A bundle's parts are fixed once it is on a document (073), so they explain each note. Rules:
--   1. (071 rule 2, widened) A note's line delivers the stock item or the bundle of its order line; a bundle only with
--      a stock part, none of which the order's invoices took out already.
--   2. (071 rule 3, widened) An invoice made from the order does not take out a part of a bundle its live notes
--      delivered.
--   3. (081, widened) What comes back on an invoice's credit notes may count the parts of the bundles it billed from
--      the notes.

CREATE OR REPLACE FUNCTION delivery_note_lines_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    note record;
    ordered record;
    delivered numeric;
    kind text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A delivery note''s lines are kept: void the note instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT sales_order_id, status INTO note FROM delivery_notes WHERE id = NEW.delivery_note_id AND company_id = NEW.company_id;
    IF NOT FOUND OR note.status <> 'posted' THEN
        RAISE EXCEPTION 'A line is added to a posted delivery note of the company' USING ERRCODE = 'check_violation';
    END IF;
    SELECT l.document_id, l.item_id, l.quantity INTO ordered FROM document_lines l
     WHERE l.id = NEW.order_line_id AND l.company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR ordered.document_id <> note.sales_order_id THEN
        RAISE EXCEPTION 'A delivery note delivers lines of its own sales order' USING ERRCODE = 'check_violation';
    END IF;
    SELECT type INTO kind FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id;
    IF NEW.item_id IS DISTINCT FROM ordered.item_id OR kind IS NULL OR kind NOT IN ('inventory', 'bundle') THEN
        RAISE EXCEPTION 'A delivery note delivers the stock item or bundle of its order line' USING ERRCODE = 'check_violation';
    END IF;
    -- Rule 1: a bundle leaves as its stock parts; without one, nothing would leave.
    IF kind = 'bundle' AND NOT EXISTS (SELECT 1 FROM item_components c JOIN items p ON p.id = c.component_id
                                        WHERE c.company_id = NEW.company_id AND c.bundle_id = NEW.item_id AND p.type = 'inventory') THEN
        RAISE EXCEPTION 'A bundle with no stock parts is not delivered on a note: it is invoiced as it is' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(dl.quantity), 0) INTO delivered
      FROM delivery_note_lines dl JOIN delivery_notes d ON d.id = dl.delivery_note_id
     WHERE dl.company_id = NEW.company_id AND dl.order_line_id = NEW.order_line_id AND d.status = 'posted';
    IF delivered + NEW.quantity > ordered.quantity THEN
        RAISE EXCEPTION 'More would be delivered than was ordered (% ordered, % already delivered)', ordered.quantity, delivered
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM stock_moves m JOIN documents i ON i.id = m.document_id
                WHERE m.company_id = NEW.company_id AND m.kind = 'sale'
                  AND (m.item_id = NEW.item_id
                       OR m.item_id IN (SELECT component_id FROM item_components WHERE company_id = NEW.company_id AND bundle_id = NEW.item_id))
                  AND i.type = 'invoice' AND i.status <> 'void' AND i.source_document_id = note.sales_order_id) THEN
        RAISE EXCEPTION 'An invoice of this order already took this item out: it is not delivered again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- Rule 2: an invoice made from an order does not take out an item the order's live notes delivered, nor a part of a
-- bundle they delivered.
CREATE OR REPLACE FUNCTION stock_moves_delivered_once() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind <> 'sale' OR NEW.document_id IS NULL OR NEW.quantity >= 0 THEN
        RETURN NEW;
    END IF;
    IF EXISTS (SELECT 1 FROM documents i
                 JOIN delivery_notes d ON d.company_id = i.company_id AND d.sales_order_id = i.source_document_id AND d.status = 'posted'
                 JOIN delivery_note_lines dl ON dl.delivery_note_id = d.id
                WHERE i.id = NEW.document_id AND i.company_id = NEW.company_id AND i.type = 'invoice'
                  AND (dl.item_id = NEW.item_id
                       OR EXISTS (SELECT 1 FROM item_components c WHERE c.company_id = dl.company_id AND c.bundle_id = dl.item_id AND c.component_id = NEW.item_id))) THEN
        RAISE EXCEPTION 'These goods left on a delivery note: the invoice bills them without moving them again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- Rule 3: 081's limit, where what the invoice billed from the notes counts a bundle's parts (its quantity per bundle
-- times the bundles matched, in the order line's unit).
CREATE OR REPLACE FUNCTION stock_moves_return_within_sale() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    credit record;
    sold   numeric;
    billed numeric;
    parts  numeric;
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
    -- 097: and the item as a part of the bundles it billed from them.
    SELECT coalesce(sum(m.quantity * coalesce(ol.unit_factor, 1) * c.quantity), 0) INTO parts
      FROM invoice_delivery_matches m
      JOIN document_lines il ON il.id = m.invoice_line_id AND il.company_id = m.company_id
      JOIN document_lines ol ON ol.id = m.order_line_id AND ol.company_id = m.company_id
      JOIN item_components c ON c.company_id = m.company_id AND c.bundle_id = il.item_id AND c.component_id = NEW.item_id
     WHERE m.company_id = NEW.company_id AND m.invoice_id = credit.original_document_id;
    -- Back so far on the invoice's credit notes, less what went out again when one of them was voided.
    SELECT coalesce(sum(m.quantity), 0) INTO back
      FROM stock_moves m JOIN documents c ON c.id = m.document_id AND c.company_id = m.company_id
     WHERE m.company_id = NEW.company_id AND c.type = 'credit_note' AND c.original_document_id = credit.original_document_id
       AND m.item_id = NEW.item_id AND m.kind IN ('sale_return', 'sale');
    IF back + NEW.quantity > sold + billed + parts THEN
        RAISE EXCEPTION 'More of an item comes back than its invoice sold (% sold, % back already)', sold + billed + parts, back
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

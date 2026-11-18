-- Ledgerly core schema, part 69: goods received notes and the three-way match (Module 3).
--
-- A goods received note says what of an issued purchase order arrived, and when. Its stock lines come into the
-- warehouse at the order's cost (Dr Inventory, Cr Goods received not invoiced) on the day they arrive. The bill for
-- those goods, when it comes, clears that account instead of bringing the stock in again: each of its stock lines is
-- matched to what was received and not yet billed. A bill priced differently from the order moves the difference onto
-- the goods still on hand (a price adjustment: value only, up or down) and the rest to cost of goods sold. Rules:
--   1. A receipt is of an issued purchase order of the company, into the company's warehouse; it is kept, and only
--      ever voided once, with the reason.
--   2. A receipt line receives a stock line of that order, the same item, a positive quantity; the live receipts never
--      receive more of an order line than was ordered.
--   3. An item of an order comes in one way: on receipts, or on the order's bills. Once a live bill of the order brought
--      it in, it is not received; once it is received, the order's bills do not bring it in.
--   4. A match names a bill made from the order and its line for that order line; the live bills never match more of
--      an order line than the live receipts received. A receipt whose goods are billed is not voided.
--   5. A price adjustment is a stock move of value only, other than zero, onto stock on hand, never taking the value
--      below zero.
--   6. An order with live receipts is not cancelled.
--   7. Receipt lines and matches are kept: never changed or deleted.

ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_kind_check;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_kind_check CHECK (kind IN ('opening', 'purchase', 'sale', 'sale_return', 'purchase_return',
    'transfer_in', 'transfer_out', 'adjustment', 'production', 'project_issue', 'landed_cost', 'price_adjustment'));
ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_quantity_check;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_quantity_check CHECK (quantity <> 0 OR kind IN ('landed_cost', 'price_adjustment'));
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_price_adjustment_value_only CHECK (kind <> 'price_adjustment' OR quantity = 0);
-- A goods received note's own moves (in, and out again on its void) name the note, not a document.
ALTER TABLE stock_moves ADD COLUMN goods_receipt_id uuid;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_one_source CHECK (document_id IS NULL OR goods_receipt_id IS NULL);

-- 064's valuation, with the price adjustment: a value-only move, up or down, onto stock on hand (rule 5).
CREATE OR REPLACE FUNCTION stock_moves_cost() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    it record;
    bal record;
    allow_negative boolean;
    layer record;
    need numeric;
    take numeric;
    portion numeric;
    taken numeric := 0;
    spread numeric;
    open_quantity numeric;
    open_value numeric;
    n integer;
    i integer := 0;
BEGIN
    SELECT type, company_id, costing INTO it FROM items WHERE id = NEW.item_id;
    IF it.company_id IS DISTINCT FROM NEW.company_id OR NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A stock move names the company''s own item and warehouse' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF it.type <> 'inventory' THEN
        RAISE EXCEPTION 'Only stock items have stock moves' USING ERRCODE = 'check_violation';
    END IF;

    INSERT INTO stock_balances (company_id, item_id, warehouse_id) VALUES (NEW.company_id, NEW.item_id, NEW.warehouse_id)
        ON CONFLICT DO NOTHING;
    SELECT quantity, value INTO bal FROM stock_balances
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id FOR UPDATE;

    IF NEW.kind IN ('landed_cost', 'price_adjustment') THEN
        IF NEW.kind = 'landed_cost' AND (NEW.quantity <> 0 OR NEW.amount IS NULL OR NEW.amount <= 0) THEN
            RAISE EXCEPTION 'A landed cost adds a value above zero, and no quantity' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.kind = 'price_adjustment' AND (NEW.quantity <> 0 OR NEW.amount IS NULL OR NEW.amount = 0) THEN
            RAISE EXCEPTION 'A price adjustment changes the value only, by an amount other than zero' USING ERRCODE = 'check_violation';
        END IF;
        IF bal.quantity <= 0 THEN
            RAISE EXCEPTION 'A landed cost or price adjustment goes onto stock still on hand' USING ERRCODE = 'check_violation';
        END IF;
        IF bal.value + NEW.amount < 0 THEN
            RAISE EXCEPTION 'A price adjustment never takes the stock''s value below zero (% on hand)', bal.value USING ERRCODE = 'check_violation';
        END IF;
        NEW.unit_cost := 0;
        IF it.costing = 'fifo' THEN
            -- Onto the layers on hand: a value added in proportion to their quantity, a value taken off in proportion
            -- to their value (so no layer goes below zero); the last takes what rounding leaves.
            SELECT coalesce(sum(remaining), 0), coalesce(sum(value_remaining), 0), count(*) INTO open_quantity, open_value, n FROM stock_layers
             WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND remaining > 0;
            spread := NEW.amount;
            FOR layer IN SELECT id, remaining, value_remaining FROM stock_layers
                          WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND remaining > 0
                          ORDER BY received_on, seq FOR UPDATE LOOP
                i := i + 1;
                portion := CASE WHEN i = n THEN spread
                                WHEN NEW.amount > 0 THEN round(NEW.amount * layer.remaining / open_quantity, 2)
                                ELSE round(NEW.amount * layer.value_remaining / open_value, 2) END;
                UPDATE stock_layers SET value_remaining = value_remaining + portion WHERE id = layer.id;
                spread := spread - portion;
            END LOOP;
        END IF;
    ELSIF NEW.quantity > 0 THEN
        NEW.amount := coalesce(NEW.amount, round(NEW.quantity * NEW.unit_cost, 2));
        IF NEW.amount < 0 THEN
            RAISE EXCEPTION 'Stock coming in has a value of zero or more' USING ERRCODE = 'check_violation';
        END IF;
        IF it.costing = 'fifo' THEN
            INSERT INTO stock_layers (company_id, item_id, warehouse_id, move_id, received_on, quantity, remaining, value_remaining)
                VALUES (NEW.company_id, NEW.item_id, NEW.warehouse_id, NEW.id, NEW.moved_on, NEW.quantity, NEW.quantity, NEW.amount);
        END IF;
    ELSIF it.costing = 'fifo' THEN
        IF bal.quantity + NEW.quantity < 0 THEN
            RAISE EXCEPTION 'Not enough stock: % on hand, % going out (a FIFO item never goes below zero)', bal.quantity, -NEW.quantity
                USING ERRCODE = 'check_violation';
        END IF;
        -- Oldest first, each layer at its own cost; a layer taken whole gives all its value.
        need := -NEW.quantity;
        FOR layer IN SELECT id, remaining, value_remaining FROM stock_layers
                      WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND remaining > 0
                      ORDER BY received_on, seq FOR UPDATE LOOP
            take := least(need, layer.remaining);
            portion := CASE WHEN take = layer.remaining THEN layer.value_remaining ELSE round(layer.value_remaining * take / layer.remaining, 2) END;
            UPDATE stock_layers SET remaining = remaining - take, value_remaining = value_remaining - portion WHERE id = layer.id;
            taken := taken + portion;
            need := need - take;
            EXIT WHEN need = 0;
        END LOOP;
        NEW.amount := -taken;
        NEW.unit_cost := round(taken / -NEW.quantity, 6);
    ELSE
        SELECT coalesce((settings -> 'inventory' ->> 'allow_negative')::boolean, false) INTO allow_negative FROM companies WHERE id = NEW.company_id;
        IF bal.quantity + NEW.quantity < 0 AND NOT allow_negative THEN
            RAISE EXCEPTION 'Not enough stock: % on hand, % going out', bal.quantity, -NEW.quantity USING ERRCODE = 'check_violation';
        END IF;
        -- Weighted average. Emptying the warehouse takes all its value; going below zero takes the last known average.
        NEW.amount := CASE
            WHEN bal.quantity + NEW.quantity = 0 THEN -bal.value
            WHEN bal.quantity > 0 THEN round(NEW.quantity * bal.value / bal.quantity, 2)
            ELSE round(NEW.quantity * NEW.unit_cost, 2) END;
        NEW.unit_cost := CASE WHEN NEW.quantity = 0 THEN 0 ELSE round(NEW.amount / NEW.quantity, 6) END;
    END IF;

    UPDATE stock_balances SET quantity = quantity + NEW.quantity, value = value + NEW.amount, updated_at = now()
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION stock_moves_cost() FROM PUBLIC;

CREATE TABLE goods_receipts (
    id                uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid        NOT NULL REFERENCES companies (id),
    purchase_order_id uuid        NOT NULL REFERENCES documents (id),
    number            text        NOT NULL,
    received_on       date        NOT NULL,
    warehouse_id      uuid        NOT NULL REFERENCES warehouses (id),
    reference         text        CHECK (length(reference) <= 100),   -- the supplier's delivery note
    note              text        CHECK (length(note) <= 500),
    status            text        NOT NULL DEFAULT 'posted' CHECK (status IN ('posted', 'void')),
    journal_entry_id  uuid,
    void_reason       text        CHECK (length(void_reason) <= 500),
    voided_by         uuid        REFERENCES users (id),
    voided_at         timestamptz,
    created_by        uuid        NOT NULL REFERENCES users (id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number),
    UNIQUE (company_id, id),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id),
    CHECK ((status = 'void') = (voided_at IS NOT NULL))
);
CREATE INDEX goods_receipts_order ON goods_receipts (company_id, purchase_order_id);
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_goods_receipt FOREIGN KEY (company_id, goods_receipt_id) REFERENCES goods_receipts (company_id, id);
CREATE INDEX stock_moves_goods_receipt_idx ON stock_moves (company_id, goods_receipt_id) WHERE goods_receipt_id IS NOT NULL;

CREATE TABLE goods_receipt_lines (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    goods_receipt_id uuid          NOT NULL REFERENCES goods_receipts (id),
    order_line_id    uuid          NOT NULL REFERENCES document_lines (id),
    item_id          uuid          NOT NULL REFERENCES items (id),
    quantity         numeric(18,2) NOT NULL CHECK (quantity > 0),
    amount           numeric(20,2) NOT NULL CHECK (amount >= 0)   -- its value at the order's cost, in the base currency
);
CREATE INDEX goods_receipt_lines_receipt ON goods_receipt_lines (company_id, goods_receipt_id);
CREATE INDEX goods_receipt_lines_order_line ON goods_receipt_lines (company_id, order_line_id);

CREATE TABLE bill_receipt_matches (
    id            uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid          NOT NULL REFERENCES companies (id),
    bill_id       uuid          NOT NULL REFERENCES documents (id),
    bill_line_id  uuid          NOT NULL REFERENCES document_lines (id),
    order_line_id uuid          NOT NULL REFERENCES document_lines (id),
    quantity      numeric(18,2) NOT NULL CHECK (quantity > 0),
    amount        numeric(20,2) NOT NULL CHECK (amount >= 0),   -- what it took off goods received not invoiced, in the base currency
    created_at    timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX bill_receipt_matches_bill ON bill_receipt_matches (company_id, bill_id);
CREATE INDEX bill_receipt_matches_order_line ON bill_receipt_matches (company_id, order_line_id);

-- Rule 1: an issued order of the company, the company's warehouse; then only the void, once.
CREATE FUNCTION goods_receipts_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    line record;
    received numeric;
    matched numeric;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A goods received note is kept: void it instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'posted' THEN
            RAISE EXCEPTION 'A goods received note is recorded as posted' USING ERRCODE = 'check_violation';
        END IF;
        PERFORM 1 FROM documents WHERE id = NEW.purchase_order_id AND company_id = NEW.company_id
                                   AND type = 'purchase_order' AND status = 'accepted' FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Goods are received against an issued purchase order of the company' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
            RAISE EXCEPTION 'Goods are received into the company''s own warehouse' USING ERRCODE = 'foreign_key_violation';
        END IF;
        RETURN NEW;
    END IF;

    -- The entry is named once; then the only change is the void, once, with who and when.
    IF (NEW.id, NEW.company_id, NEW.purchase_order_id, NEW.number, NEW.received_on, NEW.warehouse_id, NEW.reference, NEW.note, NEW.created_by, NEW.created_at)
       IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.purchase_order_id, OLD.number, OLD.received_on, OLD.warehouse_id, OLD.reference, OLD.note, OLD.created_by, OLD.created_at)
       OR (OLD.journal_entry_id IS NOT NULL AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
       OR OLD.status = 'void' THEN
        RAISE EXCEPTION 'A goods received note does not change: void it and record another' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'void' THEN
        -- Rule 4: what its goods' bills matched must still be received without it.
        FOR line IN SELECT DISTINCT order_line_id FROM goods_receipt_lines WHERE company_id = NEW.company_id AND goods_receipt_id = NEW.id LOOP
            PERFORM 1 FROM document_lines WHERE id = line.order_line_id FOR UPDATE;
            SELECT coalesce(sum(gl.quantity), 0) INTO received
              FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
             WHERE gl.company_id = NEW.company_id AND gl.order_line_id = line.order_line_id AND g.status = 'posted' AND g.id <> NEW.id;
            SELECT coalesce(sum(m.quantity), 0) INTO matched
              FROM bill_receipt_matches m JOIN documents b ON b.id = m.bill_id
             WHERE m.company_id = NEW.company_id AND m.order_line_id = line.order_line_id AND b.status <> 'void';
            IF matched > received THEN
                RAISE EXCEPTION 'The goods on this note are billed (% billed, % received without it): void the bill first', matched, received
                    USING ERRCODE = 'check_violation';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER goods_receipts_valid BEFORE INSERT OR UPDATE OR DELETE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION goods_receipts_valid();

-- Rules 2 and 3: a stock line of the note's order, never more than ordered, of an item the order's bills did not bring in.
CREATE FUNCTION goods_receipt_lines_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    receipt record;
    ordered record;
    received numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A goods received note''s lines are kept: void the note instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT purchase_order_id, status INTO receipt FROM goods_receipts WHERE id = NEW.goods_receipt_id AND company_id = NEW.company_id;
    IF NOT FOUND OR receipt.status <> 'posted' THEN
        RAISE EXCEPTION 'A line is added to a posted goods received note of the company' USING ERRCODE = 'check_violation';
    END IF;
    -- The order line, locked: two notes receiving it at once are counted one after the other.
    SELECT l.document_id, l.item_id, l.quantity INTO ordered FROM document_lines l
     WHERE l.id = NEW.order_line_id AND l.company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR ordered.document_id <> receipt.purchase_order_id THEN
        RAISE EXCEPTION 'A goods received note receives lines of its own purchase order' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.item_id IS DISTINCT FROM ordered.item_id
       OR NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND type = 'inventory') THEN
        RAISE EXCEPTION 'A goods received note receives the stock item of its order line' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(gl.quantity), 0) INTO received
      FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
     WHERE gl.company_id = NEW.company_id AND gl.order_line_id = NEW.order_line_id AND g.status = 'posted';
    IF received + NEW.quantity > ordered.quantity THEN
        RAISE EXCEPTION 'More would be received than was ordered (% ordered, % already received)', ordered.quantity, received
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM stock_moves m JOIN documents b ON b.id = m.document_id
                WHERE m.company_id = NEW.company_id AND m.kind = 'purchase' AND m.item_id = NEW.item_id
                  AND b.type = 'bill' AND b.status <> 'void' AND b.source_document_id = receipt.purchase_order_id) THEN
        RAISE EXCEPTION 'A bill of this order already brought this item in: it is not received again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER goods_receipt_lines_valid BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION goods_receipt_lines_valid();

-- Rule 4: a line of a bill made from the order, for that order line, never beyond what the live receipts received.
CREATE FUNCTION bill_receipt_matches_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    bill record;
    received numeric;
    matched numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A bill''s match to goods received is kept: void the bill instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT type, status, source_document_id INTO bill FROM documents WHERE id = NEW.bill_id AND company_id = NEW.company_id;
    IF NOT FOUND OR bill.type <> 'bill' OR bill.status = 'void'
       OR NOT EXISTS (SELECT 1 FROM document_lines WHERE id = NEW.bill_line_id AND company_id = NEW.company_id
                                                     AND document_id = NEW.bill_id AND source_line_id = NEW.order_line_id)
       OR NOT EXISTS (SELECT 1 FROM document_lines WHERE id = NEW.order_line_id AND company_id = NEW.company_id
                                                     AND document_id = bill.source_document_id) THEN
        RAISE EXCEPTION 'A match names a line of a bill made from the order, for that order line' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM document_lines WHERE id = NEW.order_line_id FOR UPDATE;
    SELECT coalesce(sum(gl.quantity), 0) INTO received
      FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
     WHERE gl.company_id = NEW.company_id AND gl.order_line_id = NEW.order_line_id AND g.status = 'posted';
    SELECT coalesce(sum(m.quantity), 0) INTO matched
      FROM bill_receipt_matches m JOIN documents b ON b.id = m.bill_id
     WHERE m.company_id = NEW.company_id AND m.order_line_id = NEW.order_line_id AND b.status <> 'void';
    IF matched + NEW.quantity > received THEN
        RAISE EXCEPTION 'More would be billed than was received (% received, % already billed)', received, matched
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER bill_receipt_matches_valid BEFORE INSERT OR UPDATE OR DELETE ON bill_receipt_matches FOR EACH ROW EXECUTE FUNCTION bill_receipt_matches_valid();

-- Rule 3, the other way: a bill made from an order does not bring in an item the order's live receipts received.
CREATE FUNCTION stock_moves_received_once() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind <> 'purchase' OR NEW.document_id IS NULL OR NEW.quantity <= 0 THEN
        RETURN NEW;
    END IF;
    IF EXISTS (SELECT 1 FROM documents b
                 JOIN goods_receipts g ON g.company_id = b.company_id AND g.purchase_order_id = b.source_document_id AND g.status = 'posted'
                 JOIN goods_receipt_lines gl ON gl.goods_receipt_id = g.id AND gl.item_id = NEW.item_id
                WHERE b.id = NEW.document_id AND b.company_id = NEW.company_id AND b.type = 'bill') THEN
        RAISE EXCEPTION 'These goods came in on a goods received note: the bill clears it instead' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER stock_moves_received_once BEFORE INSERT ON stock_moves FOR EACH ROW EXECUTE FUNCTION stock_moves_received_once();

-- Rule 6: an order with goods received is closed, not cancelled.
CREATE FUNCTION documents_received_not_cancelled() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.type = 'purchase_order' AND NEW.status = 'void' AND OLD.status <> 'void'
       AND EXISTS (SELECT 1 FROM goods_receipts WHERE company_id = NEW.company_id AND purchase_order_id = NEW.id AND status = 'posted') THEN
        RAISE EXCEPTION 'A purchase order with goods received is closed, not cancelled' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_received_not_cancelled BEFORE UPDATE OF status ON documents FOR EACH ROW EXECUTE FUNCTION documents_received_not_cancelled();

ALTER TABLE goods_receipts ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON goods_receipts
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON goods_receipts FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON goods_receipts TO {{app_role}};

ALTER TABLE goods_receipt_lines ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON goods_receipt_lines
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON goods_receipt_lines FROM {{app_role}};
GRANT SELECT, INSERT ON goods_receipt_lines TO {{app_role}};

ALTER TABLE bill_receipt_matches ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON bill_receipt_matches
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON bill_receipt_matches FROM {{app_role}};
GRANT SELECT, INSERT ON bill_receipt_matches TO {{app_role}};

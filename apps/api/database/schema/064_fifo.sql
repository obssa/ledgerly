-- Ledgerly core schema, part 64: FIFO as an option per item (Module 4).
--
-- Every item is costed at the weighted average unless it is set to FIFO before its first stock move. A FIFO item keeps
-- cost layers: each move in opens one (its quantity and value, by the day it came in); each move out takes from the
-- oldest layers first, at their own cost, so what is left is valued at the most recent costs. A landed cost goes onto
-- the layers still on hand, in proportion to their quantity. A FIFO item never goes below zero. The balance's value is
-- always the sum of the layers' values. Rules:
--   1. An item's costing is chosen before its first stock move and never changes after; only a stock item is FIFO.
--   2. Layers are written only by the valuation trigger; the application reads them.

ALTER TABLE items ADD COLUMN costing text NOT NULL DEFAULT 'average' CHECK (costing IN ('average', 'fifo'));
ALTER TABLE items ADD CONSTRAINT items_fifo_is_stock CHECK (costing = 'average' OR type = 'inventory');

CREATE FUNCTION items_costing_fixed() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.costing IS DISTINCT FROM OLD.costing
       AND EXISTS (SELECT 1 FROM stock_moves WHERE company_id = OLD.company_id AND item_id = OLD.id) THEN
        RAISE EXCEPTION 'Item % has stock moves: its costing stays as it was', OLD.name USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION items_costing_fixed() FROM PUBLIC;
CREATE TRIGGER items_costing_fixed BEFORE UPDATE OF costing ON items FOR EACH ROW EXECUTE FUNCTION items_costing_fixed();

CREATE TABLE stock_layers (
    id              uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id      uuid          NOT NULL REFERENCES companies (id),
    item_id         uuid          NOT NULL REFERENCES items (id),
    warehouse_id    uuid          NOT NULL REFERENCES warehouses (id),
    move_id         uuid          NOT NULL,   -- the move in that opened it
    received_on     date          NOT NULL,
    seq             bigserial,
    quantity        numeric(18,2) NOT NULL CHECK (quantity > 0),
    remaining       numeric(18,2) NOT NULL CHECK (remaining >= 0 AND remaining <= quantity),
    value_remaining numeric(20,2) NOT NULL CHECK (value_remaining >= 0),
    CHECK (remaining > 0 OR value_remaining = 0)
);
CREATE INDEX stock_layers_open ON stock_layers (company_id, item_id, warehouse_id, received_on, seq) WHERE remaining > 0;

ALTER TABLE stock_layers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON stock_layers
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON stock_layers FROM {{app_role}};
GRANT SELECT ON stock_layers TO {{app_role}};

-- 060's valuation, with the FIFO branch: layers opened by moves in, taken oldest first by moves out.
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

    IF NEW.kind = 'landed_cost' THEN
        IF NEW.quantity <> 0 OR NEW.amount IS NULL OR NEW.amount <= 0 THEN
            RAISE EXCEPTION 'A landed cost adds a value above zero, and no quantity' USING ERRCODE = 'check_violation';
        END IF;
        IF bal.quantity <= 0 THEN
            RAISE EXCEPTION 'A landed cost goes onto stock still on hand' USING ERRCODE = 'check_violation';
        END IF;
        NEW.unit_cost := 0;
        IF it.costing = 'fifo' THEN
            -- Onto the layers on hand, in proportion to their quantity; the last takes what rounding leaves.
            SELECT coalesce(sum(remaining), 0), count(*) INTO open_quantity, n FROM stock_layers
             WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND remaining > 0;
            spread := NEW.amount;
            FOR layer IN SELECT id, remaining FROM stock_layers
                          WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND remaining > 0
                          ORDER BY received_on, seq FOR UPDATE LOOP
                i := i + 1;
                portion := CASE WHEN i = n THEN spread ELSE round(NEW.amount * layer.remaining / open_quantity, 2) END;
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

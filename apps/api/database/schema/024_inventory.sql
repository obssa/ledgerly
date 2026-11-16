-- Ledgerly core schema, part 24: perpetual inventory at weighted average cost (Module 4).
--
-- Every stock move carries its value (amount, signed, base currency). The database keeps a running balance per item and
-- warehouse (stock_balances) and decides the cost of every move out itself, so the bin card and the ledger always agree:
--   - a move in is valued at quantity x unit cost (or the amount given);
--   - a move out takes the weighted average: quantity x (value / quantity on hand), and all of the value when it empties
--     the warehouse, so no rounding is ever left behind. Its unit cost is recorded from that.
-- Rules here:
--   1. Only stock items move, between the company's own items and warehouses.
--   2. Stock never goes below zero unless the company allows it (settings.inventory.allow_negative).
--   3. stock_balances is written only by this trigger; the application reads it.
-- FIFO cost layers (an option per item in the module document) come later; until then every item is weighted average.

ALTER TABLE stock_moves ALTER COLUMN unit_cost TYPE numeric(20,6);
ALTER TABLE stock_moves ADD COLUMN amount numeric(20,2);
UPDATE stock_moves SET amount = round(quantity * unit_cost, 2) WHERE amount IS NULL;

CREATE TABLE stock_balances (
    company_id   uuid          NOT NULL REFERENCES companies (id),
    item_id      uuid          NOT NULL REFERENCES items (id),
    warehouse_id uuid          NOT NULL REFERENCES warehouses (id),
    quantity     numeric(18,2) NOT NULL DEFAULT 0,
    value        numeric(20,2) NOT NULL DEFAULT 0,
    updated_at   timestamptz   NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, item_id, warehouse_id)
);

INSERT INTO stock_balances (company_id, item_id, warehouse_id, quantity, value)
SELECT company_id, item_id, warehouse_id, sum(quantity), sum(amount) FROM stock_moves GROUP BY company_id, item_id, warehouse_id;

ALTER TABLE stock_balances ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON stock_balances
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON stock_balances FROM {{app_role}};
GRANT SELECT ON stock_balances TO {{app_role}};

CREATE FUNCTION stock_moves_cost() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    it record;
    bal record;
    allow_negative boolean;
BEGIN
    SELECT type, company_id INTO it FROM items WHERE id = NEW.item_id;
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

    IF NEW.quantity > 0 THEN
        NEW.amount := coalesce(NEW.amount, round(NEW.quantity * NEW.unit_cost, 2));
        IF NEW.amount < 0 THEN
            RAISE EXCEPTION 'Stock coming in has a value of zero or more' USING ERRCODE = 'check_violation';
        END IF;
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

CREATE TRIGGER stock_moves_cost BEFORE INSERT ON stock_moves FOR EACH ROW EXECUTE FUNCTION stock_moves_cost();

ALTER TABLE stock_moves ALTER COLUMN amount SET NOT NULL;

-- Items: archived, never deleted (stock moves point to them); an item can have a reorder point (Module 4 replenishment).
ALTER TABLE items ADD COLUMN reorder_point numeric(18,2) CHECK (reorder_point >= 0);
REVOKE DELETE ON items, warehouses FROM {{app_role}};

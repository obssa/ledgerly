-- Ledgerly core schema, part 74: batches and expiry (Module 4).
--
-- A stock item may be tracked by batch (a lot, with an expiry date when it has one): food, medicine, chemicals. Every
-- stock move of such an item names the batch it moves; the stock of each batch in each warehouse is kept, and it never
-- goes below zero. Goods out take the batch that expires first unless the move names one. Cost stays the item's own
-- (weighted average or FIFO, 024 and 064): a batch tracks quantity and expiry, not value. Rules:
--   1. An item's tracking (none or batch) is chosen before its first stock move and never changes; only a stock item
--      is tracked.
--   2. A batch belongs to one of the company's tracked items, with a number of its own for that item.
--   3. A stock move of a tracked item that moves a quantity names one of its batches; any other move names none.
--   4. A batch's stock in a warehouse never goes below zero; it is written only by the valuation of moves.

ALTER TABLE items ADD COLUMN tracking text NOT NULL DEFAULT 'none' CHECK (tracking IN ('none', 'batch'));
ALTER TABLE items ADD CONSTRAINT items_tracking_is_stock CHECK (tracking = 'none' OR type = 'inventory');

CREATE FUNCTION items_tracking_fixed() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.tracking IS DISTINCT FROM OLD.tracking
       AND EXISTS (SELECT 1 FROM stock_moves WHERE company_id = OLD.company_id AND item_id = OLD.id) THEN
        RAISE EXCEPTION 'Item % has stock moves: its tracking stays as it was', OLD.name USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION items_tracking_fixed() FROM PUBLIC;
CREATE TRIGGER items_tracking_fixed BEFORE UPDATE OF tracking ON items FOR EACH ROW EXECUTE FUNCTION items_tracking_fixed();

CREATE TABLE stock_batches (
    id         uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id uuid        NOT NULL REFERENCES companies (id),
    item_id    uuid        NOT NULL REFERENCES items (id),
    number     text        NOT NULL CHECK (length(btrim(number)) BETWEEN 1 AND 60),
    expires_on date,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, id)
);
CREATE UNIQUE INDEX stock_batches_number ON stock_batches (company_id, item_id, upper(btrim(number)));

-- Rule 2; a batch's item and number never change (its expiry may be corrected).
CREATE FUNCTION stock_batches_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A batch is kept' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.id, NEW.company_id, NEW.item_id, NEW.number, NEW.created_at) IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.item_id, OLD.number, OLD.created_at) THEN
        RAISE EXCEPTION 'A batch''s item and number do not change' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND tracking = 'batch') THEN
        RAISE EXCEPTION 'A batch belongs to one of the company''s items tracked by batch' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER stock_batches_valid BEFORE INSERT OR UPDATE OR DELETE ON stock_batches FOR EACH ROW EXECUTE FUNCTION stock_batches_valid();

CREATE TABLE batch_balances (
    company_id   uuid          NOT NULL REFERENCES companies (id),
    item_id      uuid          NOT NULL REFERENCES items (id),
    warehouse_id uuid          NOT NULL REFERENCES warehouses (id),
    batch_id     uuid          NOT NULL,
    quantity     numeric(18,2) NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    updated_at   timestamptz   NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, item_id, warehouse_id, batch_id),
    FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id)
);

-- The batch a bill or credit-note line brings in (or back), by number and expiry; the stock move names the batch itself.
ALTER TABLE document_lines ADD COLUMN batch_number text CHECK (length(btrim(batch_number)) BETWEEN 1 AND 60),
    ADD COLUMN batch_expires_on date,
    ADD CONSTRAINT document_lines_batch_expiry CHECK (batch_expires_on IS NULL OR batch_number IS NOT NULL);

ALTER TABLE stock_moves ADD COLUMN batch_id uuid;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_batch FOREIGN KEY (company_id, batch_id) REFERENCES stock_batches (company_id, id);
CREATE INDEX stock_moves_batch_idx ON stock_moves (company_id, batch_id) WHERE batch_id IS NOT NULL;

-- Rules 3 and 4: the move names a batch of its own item exactly when it should, and the batch's stock follows it.
CREATE FUNCTION stock_moves_batch() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    tracked boolean;
    have numeric;
BEGIN
    SELECT tracking = 'batch' INTO tracked FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id;
    IF NOT coalesce(tracked, false) OR NEW.quantity = 0 THEN
        IF NEW.batch_id IS NOT NULL THEN
            RAISE EXCEPTION 'Only a stock move of an item tracked by batch, that moves a quantity, names a batch' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.batch_id IS NULL THEN
        RAISE EXCEPTION 'This item is tracked by batch: the move names its batch' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM stock_batches WHERE id = NEW.batch_id AND company_id = NEW.company_id AND item_id = NEW.item_id) THEN
        RAISE EXCEPTION 'A move names a batch of its own item' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO batch_balances (company_id, item_id, warehouse_id, batch_id) VALUES (NEW.company_id, NEW.item_id, NEW.warehouse_id, NEW.batch_id)
        ON CONFLICT DO NOTHING;
    SELECT quantity INTO have FROM batch_balances
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND batch_id = NEW.batch_id FOR UPDATE;
    IF have + NEW.quantity < 0 THEN
        RAISE EXCEPTION 'Not enough stock of batch: % on hand, % going out', have, -NEW.quantity USING ERRCODE = 'check_violation';
    END IF;
    UPDATE batch_balances SET quantity = quantity + NEW.quantity, updated_at = now()
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND batch_id = NEW.batch_id;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION stock_moves_batch() FROM PUBLIC;
CREATE TRIGGER stock_moves_batch BEFORE INSERT ON stock_moves FOR EACH ROW EXECUTE FUNCTION stock_moves_batch();

ALTER TABLE stock_batches ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON stock_batches
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON stock_batches FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON stock_batches TO {{app_role}};

ALTER TABLE batch_balances ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON batch_balances
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON batch_balances FROM {{app_role}};
GRANT SELECT ON batch_balances TO {{app_role}};

-- Evenbooks core schema, part 96: serial numbers (Module 4).
--
-- A stock item may be tracked by serial number (phones, machines, anything with a warranty): each unit is a batch of
-- one (074), named by its serial number. Every move of such an item moves one unit of one serial; a document line of
-- several units lists their serial numbers, and goods out without them take the oldest in stock. Rules (with 074's):
--   1. Tracking is none, batch or serial, chosen before the item's first stock move.
--   2. A move of an item tracked by serial number moves exactly one unit, of one of its serials.
--   3. A serial is in stock once at most: in one warehouse, one unit.
--   4. A line's batch or serial numbers may list up to 2,000 characters (one serial number is still at most 60).

ALTER TABLE items DROP CONSTRAINT IF EXISTS items_tracking_check;
ALTER TABLE items ADD CONSTRAINT items_tracking_check CHECK (tracking IN ('none', 'batch', 'serial'));

ALTER TABLE document_lines DROP CONSTRAINT IF EXISTS document_lines_batch_number_check;
ALTER TABLE document_lines ADD CONSTRAINT document_lines_batch_number_check CHECK (length(btrim(batch_number)) BETWEEN 1 AND 2000);

CREATE OR REPLACE FUNCTION stock_batches_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A batch is kept' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.id, NEW.company_id, NEW.item_id, NEW.number, NEW.created_at) IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.item_id, OLD.number, OLD.created_at) THEN
        RAISE EXCEPTION 'A batch''s item and number do not change' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND tracking IN ('batch', 'serial')) THEN
        RAISE EXCEPTION 'A batch or serial belongs to one of the company''s items tracked by batch or serial number' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- 074's rules 3 and 4, and rules 2 and 3 above.
CREATE OR REPLACE FUNCTION stock_moves_batch() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    how text;
    have numeric;
    held numeric;
BEGIN
    SELECT tracking INTO how FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id;
    IF coalesce(how, 'none') = 'none' OR NEW.quantity = 0 THEN
        IF NEW.batch_id IS NOT NULL THEN
            RAISE EXCEPTION 'Only a stock move of an item tracked by batch or serial number, that moves a quantity, names a batch' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.batch_id IS NULL THEN
        RAISE EXCEPTION 'This item is tracked by %: the move names its %', how, CASE WHEN how = 'serial' THEN 'serial number' ELSE 'batch' END USING ERRCODE = 'check_violation';
    END IF;
    IF how = 'serial' AND abs(NEW.quantity) <> 1 THEN
        RAISE EXCEPTION 'An item tracked by serial number moves one unit at a time' USING ERRCODE = 'check_violation';
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
    IF how = 'serial' AND NEW.quantity > 0 THEN
        SELECT coalesce(sum(quantity), 0) INTO held FROM batch_balances WHERE company_id = NEW.company_id AND batch_id = NEW.batch_id;
        IF held + NEW.quantity > 1 THEN
            RAISE EXCEPTION 'That serial number is in stock already' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    UPDATE batch_balances SET quantity = quantity + NEW.quantity, updated_at = now()
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND batch_id = NEW.batch_id;
    RETURN NEW;
END $$;

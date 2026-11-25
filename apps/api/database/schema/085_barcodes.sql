-- Evenbooks core schema, part 85: barcodes (Module 4).
--
-- An item may carry the barcode printed on it, and each of its other units (072) its own (a carton of 12 has a barcode
-- of its own). Scanning or typing one on an invoice, a cash sale, a quote, an order or a bill picks the item in that
-- unit. Rules:
--   1. A barcode is 4 to 64 printable characters with no spaces at either end.
--   2. Within a company a barcode names one thing: one item, or one unit of one item.

ALTER TABLE items ADD COLUMN barcode text CHECK (barcode IS NULL OR (barcode ~ '^[!-~]([ -~]{2,62})[!-~]$'));
ALTER TABLE item_units ADD COLUMN barcode text CHECK (barcode IS NULL OR (barcode ~ '^[!-~]([ -~]{2,62})[!-~]$'));
CREATE UNIQUE INDEX items_barcode ON items (company_id, barcode) WHERE barcode IS NOT NULL;
CREATE UNIQUE INDEX item_units_barcode ON item_units (company_id, barcode) WHERE barcode IS NOT NULL;

-- Rule 2 across the two tables; a lock on the barcode itself keeps two saves of the same one from both passing.
CREATE FUNCTION barcodes_once() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.barcode IS NULL OR (TG_OP = 'UPDATE' AND NEW.barcode IS NOT DISTINCT FROM OLD.barcode) THEN
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('barcode:' || NEW.company_id::text || ':' || NEW.barcode, 0));
    IF (TG_TABLE_NAME = 'items' AND EXISTS (SELECT 1 FROM item_units WHERE company_id = NEW.company_id AND barcode = NEW.barcode))
       OR (TG_TABLE_NAME = 'item_units' AND EXISTS (SELECT 1 FROM items WHERE company_id = NEW.company_id AND barcode = NEW.barcode)) THEN
        RAISE EXCEPTION 'Barcode % is already in use in this company', NEW.barcode USING ERRCODE = 'unique_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION barcodes_once() FROM PUBLIC;
CREATE TRIGGER items_barcode_once BEFORE INSERT OR UPDATE OF barcode ON items FOR EACH ROW EXECUTE FUNCTION barcodes_once();
CREATE TRIGGER item_units_barcode_once BEFORE INSERT OR UPDATE OF barcode ON item_units FOR EACH ROW EXECUTE FUNCTION barcodes_once();

-- Ledgerly core schema, part 75: minimum and maximum levels, suggested purchase orders (Module 4).
--
-- A stock item's reorder point (024) is its minimum: at or below it, counting what is on order and what the open sales
-- orders hold, it is running low. Its maximum level is what an order brings it back up to, and its preferred supplier
-- is who the suggested purchase order goes to. Nothing here moves stock or posts: the suggestions become draft purchase
-- orders (068), issued as any other. Rules:
--   1. A maximum level is zero or more, and never below the item's reorder point.
--   2. A preferred supplier is one of the company's own suppliers.

ALTER TABLE items ADD COLUMN max_level numeric(18,2) CHECK (max_level >= 0),
    ADD COLUMN preferred_supplier_id uuid REFERENCES contacts (id),
    ADD CONSTRAINT items_levels_ordered CHECK (max_level IS NULL OR reorder_point IS NULL OR max_level >= reorder_point);

CREATE FUNCTION items_preferred_supplier_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.preferred_supplier_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contacts WHERE id = NEW.preferred_supplier_id AND company_id = NEW.company_id AND is_vendor) THEN
        RAISE EXCEPTION 'An item''s preferred supplier is one of the company''s suppliers' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION items_preferred_supplier_valid() FROM PUBLIC;
CREATE TRIGGER items_preferred_supplier_valid BEFORE INSERT OR UPDATE OF preferred_supplier_id, company_id ON items
    FOR EACH ROW EXECUTE FUNCTION items_preferred_supplier_valid();

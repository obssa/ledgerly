-- Ledgerly core schema, part 89: item variants (Module 4).
--
-- An item sold in sizes, colours or other options is a parent with variants: each variant is an item of its own (its
-- own stock, SKU, barcode and prices), named by its options ("M / Red") and grouped under the parent. Rules:
--   1. A variant's parent is another item of the company that is not itself a variant (one level), of the same type.
--   2. A variant has a label; a parent never has two variants with the same label.
--   3. A parent with variants stays of its type, and an item with variants does not become a variant.

ALTER TABLE items ADD COLUMN parent_item_id uuid REFERENCES items (id),
    ADD COLUMN variant_label text CHECK (variant_label IS NULL OR length(btrim(variant_label)) BETWEEN 1 AND 120),
    ADD CONSTRAINT items_variant_shape CHECK ((parent_item_id IS NULL) = (variant_label IS NULL) AND (parent_item_id IS NULL OR parent_item_id <> id));
CREATE UNIQUE INDEX items_variant_label ON items (company_id, parent_item_id, lower(btrim(variant_label))) WHERE parent_item_id IS NOT NULL;

CREATE FUNCTION items_variant_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    -- Rule 1, when a variant names its parent (or changes its type).
    IF NEW.parent_item_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.parent_item_id IS DISTINCT FROM OLD.parent_item_id OR NEW.type IS DISTINCT FROM OLD.type)
       AND NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.parent_item_id AND company_id = NEW.company_id AND parent_item_id IS NULL AND type = NEW.type) THEN
        RAISE EXCEPTION 'A variant belongs to another item of the company, of its type, that is not a variant itself' USING ERRCODE = 'check_violation';
    END IF;
    -- Rule 3.
    IF TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM items WHERE company_id = NEW.company_id AND parent_item_id = NEW.id) THEN
        IF NEW.type IS DISTINCT FROM OLD.type THEN
            RAISE EXCEPTION 'An item with variants stays of its type' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.parent_item_id IS NOT NULL THEN
            RAISE EXCEPTION 'An item with variants does not become a variant' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION items_variant_valid() FROM PUBLIC;
CREATE TRIGGER items_variant_valid BEFORE INSERT OR UPDATE OF parent_item_id, variant_label, type ON items FOR EACH ROW EXECUTE FUNCTION items_variant_valid();

-- Evenbooks core schema, part 98: bins (Module 4).
--
-- A bin is a place inside a warehouse ("A-01", "Cold room", "Shelf 3"). Each stock item can be given the bin it is
-- kept in, one per warehouse, so a stock count can be taken one bin at a time: the count sheet lists what each bin
-- holds, in bin order. The quantity on hand stays the warehouse's (no stock move names a bin): bins say where to look,
-- not how much is there. Rules:
--   1. A bin is in one of the company's warehouses, its code once there whatever its case; it keeps its warehouse,
--      and is archived, never deleted, and not while items are kept in it.
--   2. An item is kept in one bin per warehouse: an active bin of that warehouse, and the item a stock item of the
--      company.

CREATE TABLE bins (
    id            uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid        NOT NULL REFERENCES companies (id),
    warehouse_id  uuid        NOT NULL REFERENCES warehouses (id),
    code          text        NOT NULL CHECK (length(btrim(code)) BETWEEN 1 AND 30),
    name          text        CHECK (name IS NULL OR length(name) <= 120),
    is_active     boolean     NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX bins_code_once ON bins (company_id, warehouse_id, upper(btrim(code)));

CREATE TABLE item_bins (
    id            uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid        NOT NULL REFERENCES companies (id),
    item_id       uuid        NOT NULL REFERENCES items (id),
    warehouse_id  uuid        NOT NULL REFERENCES warehouses (id),
    bin_id        uuid        NOT NULL REFERENCES bins (id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, item_id, warehouse_id)
);
CREATE INDEX item_bins_bin ON item_bins (company_id, bin_id);

-- Rule 1.
CREATE FUNCTION bins_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A bin is archived, never deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.warehouse_id, NEW.created_at) IS DISTINCT FROM (OLD.company_id, OLD.warehouse_id, OLD.created_at) THEN
        RAISE EXCEPTION 'A bin stays in its warehouse: archive it and add one in the other' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A bin is in one of the company''s warehouses' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.is_active AND NOT NEW.is_active
       AND EXISTS (SELECT 1 FROM item_bins WHERE company_id = NEW.company_id AND bin_id = NEW.id) THEN
        RAISE EXCEPTION 'Bin % still has items kept in it: move them to another bin first', OLD.code USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER bins_valid BEFORE INSERT OR UPDATE OR DELETE ON bins FOR EACH ROW EXECUTE FUNCTION bins_valid();

-- Rule 2.
CREATE FUNCTION item_bins_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;   -- an item no longer kept in a bin: it is counted with the warehouse's others
    END IF;
    IF NOT EXISTS (SELECT 1 FROM bins WHERE id = NEW.bin_id AND company_id = NEW.company_id AND warehouse_id = NEW.warehouse_id AND is_active) THEN
        RAISE EXCEPTION 'An item is kept in an active bin of that warehouse' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND type = 'inventory') THEN
        RAISE EXCEPTION 'Only a stock item of the company is kept in a bin' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER item_bins_valid BEFORE INSERT OR UPDATE OR DELETE ON item_bins FOR EACH ROW EXECUTE FUNCTION item_bins_valid();

ALTER TABLE bins ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON bins
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
ALTER TABLE item_bins ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_bins
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON bins, item_bins FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON bins TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON item_bins TO {{app_role}};

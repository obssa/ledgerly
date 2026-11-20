-- Ledgerly core schema, part 90: bin locations (Module 4).
--
-- Where a stock item is kept in each warehouse ("Aisle 3 / B2"), so it is picked, counted and put away in the right
-- place. A location is where to look; the stock itself is still counted per warehouse (024). Rules:
--   1. A location is for one of the company's stock items in one of its warehouses, once per item and warehouse.
--   2. It is 1 to 40 characters.

CREATE TABLE item_locations (
    id           uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid        NOT NULL REFERENCES companies (id),
    item_id      uuid        NOT NULL REFERENCES items (id),
    warehouse_id uuid        NOT NULL REFERENCES warehouses (id),
    bin          text        NOT NULL CHECK (length(btrim(bin)) BETWEEN 1 AND 40),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, item_id, warehouse_id)
);
CREATE INDEX item_locations_warehouse ON item_locations (company_id, warehouse_id, bin);

CREATE FUNCTION item_locations_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND type = 'inventory')
       OR NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A bin location is for one of the company''s stock items in one of its warehouses' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER item_locations_valid BEFORE INSERT OR UPDATE ON item_locations FOR EACH ROW EXECUTE FUNCTION item_locations_valid();

ALTER TABLE item_locations ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_locations
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON item_locations FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON item_locations TO {{app_role}};

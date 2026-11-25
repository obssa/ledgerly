-- Evenbooks core schema, part 87: assemblies, a bill of materials (Module 4).
--
-- A stock item may be made from other stock items: its parts list says how many of each go into one. Building some
-- takes the parts out of a warehouse at their cost and puts the assembled item in at the sum of that cost (production
-- moves, 003's reserved kind). Value moves from the parts to the item and nothing is made or lost, so the inventory
-- account is untouched and no entry is posted. A build is kept; a mistake is corrected with a stock adjustment. Rules:
--   1. An assembly is one of the company's stock items, not tracked by batch; its parts are other stock items of the
--      company, each once, a positive quantity.
--   2. A build is of an assembly of the company, in one of its warehouses, a positive quantity; it is kept as made.
--   3. A production move names its build, of the company; a build's moves add up to no value at all (checked when the
--      transaction commits, after all of them are written).

CREATE TABLE item_assembly_parts (
    id           uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid          NOT NULL REFERENCES companies (id),
    assembly_id  uuid          NOT NULL REFERENCES items (id),
    part_id      uuid          NOT NULL REFERENCES items (id),
    quantity     numeric(18,4) NOT NULL CHECK (quantity > 0),
    created_at   timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, assembly_id, part_id),
    CHECK (assembly_id <> part_id)
);
CREATE INDEX item_assembly_parts_assembly ON item_assembly_parts (company_id, assembly_id);

CREATE TABLE assembly_builds (
    id            uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid          NOT NULL REFERENCES companies (id),
    item_id       uuid          NOT NULL REFERENCES items (id),
    warehouse_id  uuid          NOT NULL REFERENCES warehouses (id),
    quantity      numeric(18,2) NOT NULL CHECK (quantity > 0),
    built_on      date          NOT NULL,
    reference     text          CHECK (length(reference) <= 100),
    value         numeric(20,2),
    created_by    uuid          NOT NULL REFERENCES users (id),
    created_at    timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, id)
);
CREATE INDEX assembly_builds_item ON assembly_builds (company_id, item_id, built_on);

ALTER TABLE stock_moves ADD COLUMN assembly_build_id uuid,
    ADD CONSTRAINT stock_moves_assembly_build FOREIGN KEY (company_id, assembly_build_id) REFERENCES assembly_builds (company_id, id),
    ADD CONSTRAINT stock_moves_production_names_build CHECK ((kind = 'production') = (assembly_build_id IS NOT NULL));
ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_one_source;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_one_source
    CHECK (num_nonnulls(document_id, goods_receipt_id, delivery_note_id, assembly_build_id) <= 1);

-- Rule 1.
CREATE FUNCTION item_assembly_parts_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.assembly_id AND company_id = NEW.company_id AND type = 'inventory' AND tracking = 'none') THEN
        RAISE EXCEPTION 'An assembly is one of the company''s stock items, not tracked by batch' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.part_id AND company_id = NEW.company_id AND type = 'inventory') THEN
        RAISE EXCEPTION 'A part of an assembly is another of the company''s stock items' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER item_assembly_parts_valid BEFORE INSERT OR UPDATE OR DELETE ON item_assembly_parts FOR EACH ROW EXECUTE FUNCTION item_assembly_parts_valid();

-- Rule 2.
CREATE FUNCTION assembly_builds_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND (to_jsonb(NEW) - 'value') <> (to_jsonb(OLD) - 'value') THEN
        RAISE EXCEPTION 'A build is kept as it was made: correct a mistake with a stock adjustment' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RETURN NEW;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM item_assembly_parts WHERE company_id = NEW.company_id AND assembly_id = NEW.item_id) THEN
        RAISE EXCEPTION 'A build is of an assembly of the company: give the item its parts first' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A build is made in one of the company''s warehouses' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER assembly_builds_valid BEFORE INSERT OR UPDATE ON assembly_builds FOR EACH ROW EXECUTE FUNCTION assembly_builds_valid();

-- Rule 3, when the transaction commits (a constraint trigger on the build, not on the partitioned moves): the build's
-- moves are the parts out and the item in, once, and add up to no value.
CREATE FUNCTION assembly_builds_balanced() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    total numeric;
    ins int;
    outs int;
BEGIN
    SELECT coalesce(sum(amount), 0), count(*) FILTER (WHERE quantity > 0 AND item_id = NEW.item_id), count(*) FILTER (WHERE quantity < 0)
      INTO total, ins, outs
      FROM stock_moves WHERE company_id = NEW.company_id AND assembly_build_id = NEW.id;
    IF total <> 0 OR ins <> 1 OR outs = 0 THEN
        RAISE EXCEPTION 'A build moves the value of its parts into the item it makes, no more and no less (% left over)', total
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;
REVOKE ALL ON FUNCTION assembly_builds_balanced() FROM PUBLIC;
CREATE CONSTRAINT TRIGGER assembly_builds_balanced AFTER INSERT ON assembly_builds DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION assembly_builds_balanced();

ALTER TABLE item_assembly_parts ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_assembly_parts
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON item_assembly_parts FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON item_assembly_parts TO {{app_role}};

ALTER TABLE assembly_builds ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON assembly_builds
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON assembly_builds FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE (value) ON assembly_builds TO {{app_role}};

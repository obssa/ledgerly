-- Ledgerly core schema, part 72: units of measure (Module 4).
--
-- An item is counted in its own unit (items.unit: each, kg, bag); stock, its cost and every stock move are always in
-- that unit. An item may also be bought or sold in other units, each a fixed number of the item's own: a carton of 12,
-- a 25 kg sack. A document line names the unit it is in; its quantity and price are in that unit, and the stock it
-- moves is its quantity times the unit's factor (rounded to 2 decimals). Rules:
--   1. A unit belongs to one of the company's items, with a name of its own (not the item's own unit) and a factor
--      above zero. Its name and factor never change once made (it is archived instead), and it is never deleted.
--   2. A document line in another unit names a unit of its own item, active when it is chosen; the line keeps the
--      unit's name and factor as they were then. A line in the item's own unit names none and has a factor of 1.
--   3. A line made from an order line (067, 068) is in the order line's unit, so what was ordered, delivered, received
--      and billed is counted alike.

CREATE TABLE item_units (
    id         uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id uuid          NOT NULL REFERENCES companies (id),
    item_id    uuid          NOT NULL REFERENCES items (id),
    name       text          NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 30),
    factor     numeric(18,6) NOT NULL CHECK (factor > 0),
    is_active  boolean       NOT NULL DEFAULT true,
    created_by uuid          REFERENCES users (id),
    created_at timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, id)
);
CREATE UNIQUE INDEX item_units_name ON item_units (company_id, item_id, lower(btrim(name)));

-- Rule 1.
CREATE FUNCTION item_units_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    own text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A unit is kept: archive it instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.id, NEW.company_id, NEW.item_id, NEW.name, NEW.factor, NEW.created_by, NEW.created_at)
           IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.item_id, OLD.name, OLD.factor, OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'A unit''s name and size do not change: archive it and add another' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    SELECT unit INTO own FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'A unit belongs to one of the company''s items' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF lower(btrim(NEW.name)) = lower(btrim(own)) THEN
        RAISE EXCEPTION 'The item is already counted in %: a unit is another one', own USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER item_units_valid BEFORE INSERT OR UPDATE OR DELETE ON item_units FOR EACH ROW EXECUTE FUNCTION item_units_valid();

ALTER TABLE document_lines
    ADD COLUMN unit_id     uuid,
    ADD COLUMN unit_name   text,
    ADD COLUMN unit_factor numeric(18,6) NOT NULL DEFAULT 1 CHECK (unit_factor > 0),
    ADD CONSTRAINT document_lines_unit FOREIGN KEY (company_id, unit_id) REFERENCES item_units (company_id, id),
    ADD CONSTRAINT document_lines_unit_named CHECK ((unit_id IS NULL) = (unit_name IS NULL) AND (unit_id IS NOT NULL OR unit_factor = 1));

-- Rules 2 and 3: the unit is the line's item's, its name and factor are kept as chosen, and a line made from an order
-- line is in that line's unit.
CREATE FUNCTION document_lines_unit_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    u record;
    ordered uuid;
BEGIN
    IF NEW.unit_id IS NULL THEN
        NEW.unit_name := NULL;
        NEW.unit_factor := 1;
    ELSIF TG_OP = 'UPDATE' AND NEW.unit_id = OLD.unit_id AND NEW.item_id IS NOT DISTINCT FROM OLD.item_id THEN
        NEW.unit_name := OLD.unit_name;     -- kept as it was chosen
        NEW.unit_factor := OLD.unit_factor;
    ELSE
        SELECT item_id, name, factor, is_active INTO u FROM item_units WHERE id = NEW.unit_id AND company_id = NEW.company_id;
        IF NOT FOUND OR u.item_id IS DISTINCT FROM NEW.item_id THEN
            RAISE EXCEPTION 'A line''s unit is one of its own item''s units' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT u.is_active THEN
            RAISE EXCEPTION 'The unit % is archived' , u.name USING ERRCODE = 'check_violation';
        END IF;
        NEW.unit_name := u.name;
        NEW.unit_factor := u.factor;
    END IF;
    IF NEW.source_line_id IS NOT NULL THEN
        SELECT unit_id INTO ordered FROM document_lines WHERE id = NEW.source_line_id AND company_id = NEW.company_id;
        IF ordered IS DISTINCT FROM NEW.unit_id THEN
            RAISE EXCEPTION 'A line made from an order line is in the order line''s unit' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_lines_unit_valid BEFORE INSERT OR UPDATE ON document_lines FOR EACH ROW EXECUTE FUNCTION document_lines_unit_valid();

ALTER TABLE item_units ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_units
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON item_units FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON item_units TO {{app_role}};

-- Ledgerly core schema, part 73: bundles and kits (Module 4).
--
-- A bundle is an item sold as one, made of other items: a gift box of two bags of coffee and a mug. It has its own
-- price and holds no stock itself; selling it (on an invoice or a cash sale) takes its stock parts out, each its
-- quantity per bundle times the bundles sold, at their own cost. Its parts are set while it has not been used, and are
-- fixed once it is on any document, so what each sale took out stays explained. Rules:
--   1. A part belongs to a bundle of the company and is another of the company's items, never a bundle itself, once
--      per bundle, with a quantity above zero (in the part's own unit).
--   2. A bundle's parts do not change once the bundle is on a document line.
--   3. A bundle is not a stock item: it has no stock moves of its own (024's rule: only stock items have stock moves).

CREATE TABLE item_components (
    id           uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid          NOT NULL REFERENCES companies (id),
    bundle_id    uuid          NOT NULL REFERENCES items (id),
    component_id uuid          NOT NULL REFERENCES items (id),
    quantity     numeric(18,2) NOT NULL CHECK (quantity > 0),
    created_at   timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, bundle_id, component_id),
    CHECK (bundle_id <> component_id)
);
CREATE INDEX item_components_bundle ON item_components (company_id, bundle_id);

CREATE FUNCTION item_components_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    bundle uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.bundle_id ELSE NEW.bundle_id END;
    company uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.company_id ELSE NEW.company_id END;
BEGIN
    -- Rule 2: locked, so a sale and a change to its parts are one after the other.
    PERFORM 1 FROM items WHERE id = bundle AND company_id = company FOR UPDATE;
    IF EXISTS (SELECT 1 FROM document_lines WHERE company_id = company AND item_id = bundle) THEN
        RAISE EXCEPTION 'This bundle is on documents already: its parts stay as they were. Archive it and make a new one'
            USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.bundle_id, NEW.component_id, NEW.company_id) IS DISTINCT FROM (OLD.bundle_id, OLD.component_id, OLD.company_id) THEN
        RAISE EXCEPTION 'A part stays the part of its bundle: remove it and add another' USING ERRCODE = 'check_violation';
    END IF;
    -- Rule 1.
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.bundle_id AND company_id = NEW.company_id AND type = 'bundle') THEN
        RAISE EXCEPTION 'Parts belong to a bundle of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.component_id AND company_id = NEW.company_id AND type <> 'bundle') THEN
        RAISE EXCEPTION 'A part is another of the company''s items, not a bundle' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER item_components_valid BEFORE INSERT OR UPDATE OR DELETE ON item_components FOR EACH ROW EXECUTE FUNCTION item_components_valid();

-- A bundle that has parts stays a bundle; an item that is a part does not become one.
CREATE FUNCTION items_bundle_type_kept() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.type IS DISTINCT FROM OLD.type THEN
        IF OLD.type = 'bundle' AND EXISTS (SELECT 1 FROM item_components WHERE company_id = OLD.company_id AND bundle_id = OLD.id) THEN
            RAISE EXCEPTION 'A bundle with parts stays a bundle' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.type = 'bundle' AND EXISTS (SELECT 1 FROM item_components WHERE company_id = OLD.company_id AND component_id = OLD.id) THEN
            RAISE EXCEPTION 'An item that is part of a bundle does not become a bundle' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER items_bundle_type_kept BEFORE UPDATE OF type ON items FOR EACH ROW EXECUTE FUNCTION items_bundle_type_kept();

ALTER TABLE item_components ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_components
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON item_components FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON item_components TO {{app_role}};

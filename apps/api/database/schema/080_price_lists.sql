-- Module 2: customer groups and price lists (080). A price list is a group of customers who pay its prices: a name, a
-- currency, and a price for each item it lists. A customer is on one list or none. On an invoice, quote or order in the
-- list's currency, choosing an item suggests the list's price for that customer; any price can still be typed. Prices
-- are suggestions, so nothing posted depends on them. Rules here:
--   1. A list's prices are for the company's own items; a customer's list is the company's own and still in use.
--   2. A price is never negative; an item is listed once per list.
--   3. Another company never sees a list or its prices.

CREATE TABLE price_lists (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid NOT NULL REFERENCES companies (id),
    name        text NOT NULL CHECK (length(btrim(name)) BETWEEN 2 AND 120),
    currency    char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    notes       text CHECK (notes IS NULL OR length(notes) <= 500),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, id)
);
CREATE UNIQUE INDEX price_lists_name ON price_lists (company_id, lower(btrim(name)));

CREATE TABLE price_list_prices (
    company_id     uuid NOT NULL REFERENCES companies (id),
    price_list_id  uuid NOT NULL,
    item_id        uuid NOT NULL REFERENCES items (id),
    unit_price     numeric(20, 2) NOT NULL CHECK (unit_price >= 0),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (price_list_id, item_id),
    FOREIGN KEY (company_id, price_list_id) REFERENCES price_lists (company_id, id)
);

ALTER TABLE contacts ADD COLUMN price_list_id uuid REFERENCES price_lists (id);

-- Rule 1, for prices.
CREATE FUNCTION price_list_prices_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A price list prices the company''s own items' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION price_list_prices_valid() FROM PUBLIC;
CREATE TRIGGER price_list_prices_valid BEFORE INSERT OR UPDATE ON price_list_prices FOR EACH ROW EXECUTE FUNCTION price_list_prices_valid();

CREATE FUNCTION price_lists_touch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER price_lists_touch BEFORE UPDATE ON price_lists FOR EACH ROW EXECUTE FUNCTION price_lists_touch();

-- Rule 1, for customers: set to a list of the company that is in use, on a customer.
CREATE FUNCTION contacts_price_list_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.price_list_id IS NULL OR (TG_OP = 'UPDATE' AND NEW.price_list_id IS NOT DISTINCT FROM OLD.price_list_id) THEN
        RETURN NEW;
    END IF;
    IF NOT NEW.is_customer OR NOT EXISTS (SELECT 1 FROM price_lists WHERE id = NEW.price_list_id AND company_id = NEW.company_id AND is_active) THEN
        RAISE EXCEPTION 'A customer is put on one of the company''s price lists in use' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION contacts_price_list_valid() FROM PUBLIC;
CREATE TRIGGER contacts_price_list_valid BEFORE INSERT OR UPDATE OF price_list_id ON contacts FOR EACH ROW EXECUTE FUNCTION contacts_price_list_valid();

-- Rule 3.
ALTER TABLE price_lists ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON price_lists
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON price_lists FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON price_lists TO {{app_role}};

ALTER TABLE price_list_prices ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON price_list_prices
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON price_list_prices FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON price_list_prices TO {{app_role}};

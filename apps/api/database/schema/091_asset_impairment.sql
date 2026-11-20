-- Ledgerly core schema, part 91: impairment of fixed assets (Module 9c).
--
-- When an asset is worth less than its carrying amount (damaged, idle, obsolete), it is written down (IFRS for SMEs,
-- section 27): Dr the asset's depreciation and impairment expense, Cr its accumulated depreciation, in one entry of its
-- own; afterwards the lower amount is depreciated over the asset's remaining life. Rules:
--   1. An impairment is of an active asset of the company, dated on or after it was acquired, above zero, and never more
--      than the carrying amount left above the residual value (cost less depreciation and impairments so far).
--   2. It names its own entry (source asset_impairment, the impairment's id).
--   3. It is kept: never changed or deleted.

CREATE TABLE asset_impairments (
    id           uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid          NOT NULL REFERENCES companies (id),
    asset_id     uuid          NOT NULL REFERENCES fixed_assets (id),
    impaired_on  date          NOT NULL,
    amount       numeric(20,2) NOT NULL CHECK (amount > 0),
    reason       text          NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 500),
    entry_id     uuid          NOT NULL,
    created_by   uuid          NOT NULL REFERENCES users (id),
    created_at   timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX asset_impairments_asset ON asset_impairments (company_id, asset_id);

CREATE FUNCTION asset_impairments_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    a record;
    charged numeric;
    impaired numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'An impairment is kept as it was posted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT company_id, status, acquired_on, cost, residual_value, opening_depreciation INTO a
      FROM fixed_assets WHERE id = NEW.asset_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR a.status <> 'active' OR NEW.impaired_on < a.acquired_on THEN
        RAISE EXCEPTION 'An impairment is of an active asset of the company, on or after it was acquired' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(amount), 0) INTO charged FROM asset_depreciation WHERE company_id = NEW.company_id AND asset_id = NEW.asset_id;
    SELECT coalesce(sum(amount), 0) INTO impaired FROM asset_impairments WHERE company_id = NEW.company_id AND asset_id = NEW.asset_id;
    IF NEW.amount > a.cost - a.residual_value - a.opening_depreciation - charged - impaired THEN
        RAISE EXCEPTION 'An impairment is no more than the carrying amount left above the residual value (%)',
            a.cost - a.residual_value - a.opening_depreciation - charged - impaired USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.entry_id
                     AND source_type = 'asset_impairment' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'An impairment names its own entry' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION asset_impairments_valid() FROM PUBLIC;
CREATE TRIGGER asset_impairments_valid BEFORE INSERT OR UPDATE OR DELETE ON asset_impairments FOR EACH ROW EXECUTE FUNCTION asset_impairments_valid();

ALTER TABLE asset_impairments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON asset_impairments
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON asset_impairments FROM {{app_role}};
GRANT SELECT, INSERT ON asset_impairments TO {{app_role}};

-- Evenbooks core schema, part 92: revaluation of fixed assets (Module 9c).
--
-- IFRS for SMEs (17.15B to 17.15D) lets property, plant and equipment be carried at a revalued amount: its fair value
-- on the day, less the depreciation after it. Revaluing an asset carried at C to a fair value F moves it by F - C, in
-- one entry of its own (source asset_revaluation):
--   an increase: Dr the asset's cost account; Cr its depreciation and impairment expense as far as it reverses earlier
--     revaluation decreases of the same asset charged to profit or loss, the rest Cr the revaluation surplus (equity,
--     through other comprehensive income);
--   a decrease: Dr the revaluation surplus as far as the asset has a surplus, the rest Dr its depreciation and
--     impairment expense; Cr its accumulated depreciation.
-- Afterwards the revalued amount is depreciated over the asset's remaining life. Rules:
--   1. A revaluation is of an active asset of the company, on or after it was acquired, to a fair value no lower than
--      its residual value and other than its carrying amount.
--   2. The carrying amount before is the asset's as the books stand (asset_carrying_amount: cost, less opening and
--      posted depreciation and impairments, plus earlier revaluations); the split is as above, exactly.
--   3. The surplus is an active equity account of the company, the same for every revaluation of the asset.
--   4. It names its own entry; it is kept, never changed or deleted.
--   5. Depreciation (042) and impairment (091) stop at the carrying amount above the residual value, revaluations
--      counted: a revalued asset is depreciated on its revalued amount.

CREATE TABLE asset_revaluations (
    id                  uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id          uuid          NOT NULL REFERENCES companies (id),
    asset_id            uuid          NOT NULL REFERENCES fixed_assets (id),
    revalued_on         date          NOT NULL,
    fair_value          numeric(20,2) NOT NULL CHECK (fair_value >= 0),
    carrying_before     numeric(20,2) NOT NULL,
    to_surplus          numeric(20,2) NOT NULL,
    to_profit           numeric(20,2) NOT NULL,
    surplus_account_id  uuid          NOT NULL REFERENCES accounts (id),
    reason              text          NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 500),
    entry_id            uuid          NOT NULL,
    created_by          uuid          NOT NULL REFERENCES users (id),
    created_at          timestamptz   NOT NULL DEFAULT now(),
    CHECK (fair_value <> carrying_before),
    CHECK (to_surplus + to_profit = fair_value - carrying_before)
);
CREATE INDEX asset_revaluations_asset ON asset_revaluations (company_id, asset_id);

-- What the asset is carried at as the books stand: cost, less opening and posted depreciation and impairments, plus
-- the revaluations. Read by the rules below, which run as the owner.
CREATE FUNCTION asset_carrying_amount(p_company uuid, p_asset uuid) RETURNS numeric
LANGUAGE sql STABLE SET search_path = {{schema}}, pg_temp AS $$
    SELECT a.cost - a.opening_depreciation
           - coalesce((SELECT sum(amount) FROM asset_depreciation WHERE company_id = p_company AND asset_id = p_asset), 0)
           - coalesce((SELECT sum(amount) FROM asset_impairments WHERE company_id = p_company AND asset_id = p_asset), 0)
           + coalesce((SELECT sum(to_surplus + to_profit) FROM asset_revaluations WHERE company_id = p_company AND asset_id = p_asset), 0)
      FROM fixed_assets a WHERE a.company_id = p_company AND a.id = p_asset
$$;
REVOKE ALL ON FUNCTION asset_carrying_amount(uuid, uuid) FROM PUBLIC;

CREATE FUNCTION asset_revaluations_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    a record;
    carrying numeric;
    surplus numeric;
    profit numeric;
    change numeric;
    expected numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A revaluation is kept as it was posted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT company_id, code, status, acquired_on, residual_value INTO a
      FROM fixed_assets WHERE id = NEW.asset_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR a.status <> 'active' OR NEW.revalued_on < a.acquired_on THEN
        RAISE EXCEPTION 'A revaluation is of an active asset of the company, on or after it was acquired' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.fair_value < a.residual_value THEN
        RAISE EXCEPTION 'Asset % is revalued to no less than its residual value (%)', a.code, a.residual_value USING ERRCODE = 'check_violation';
    END IF;
    carrying := asset_carrying_amount(NEW.company_id, NEW.asset_id);
    IF NEW.carrying_before <> carrying THEN
        RAISE EXCEPTION 'A revaluation starts from the carrying amount as the books stand (%)', carrying USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(to_surplus), 0), coalesce(sum(to_profit), 0) INTO surplus, profit
      FROM asset_revaluations WHERE company_id = NEW.company_id AND asset_id = NEW.asset_id;
    change := NEW.fair_value - carrying;
    -- An increase goes to profit or loss as far as it reverses decreases charged there; a decrease takes the surplus first.
    expected := CASE WHEN change > 0 THEN least(change, greatest(-profit, 0)) ELSE change + least(-change, surplus) END;
    IF NEW.to_profit <> expected THEN
        RAISE EXCEPTION 'A revaluation of % puts % through profit or loss and the rest to the surplus', change, expected USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM accounts WHERE company_id = NEW.company_id AND id = NEW.surplus_account_id AND type = 'equity' AND is_active)
       OR EXISTS (SELECT 1 FROM asset_revaluations WHERE company_id = NEW.company_id AND asset_id = NEW.asset_id AND surplus_account_id <> NEW.surplus_account_id) THEN
        RAISE EXCEPTION 'The surplus is an active equity account of the company, the same for every revaluation of the asset' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.entry_id
                     AND source_type = 'asset_revaluation' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'A revaluation names its own entry' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION asset_revaluations_valid() FROM PUBLIC;
CREATE TRIGGER asset_revaluations_valid BEFORE INSERT OR UPDATE OR DELETE ON asset_revaluations FOR EACH ROW EXECUTE FUNCTION asset_revaluations_valid();

ALTER TABLE asset_revaluations ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON asset_revaluations
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON asset_revaluations FROM {{app_role}};
GRANT SELECT, INSERT ON asset_revaluations TO {{app_role}};

-- Rule 5: depreciation stops at the carrying amount above the residual value, impairments and revaluations counted.
CREATE OR REPLACE FUNCTION asset_depreciation_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    a record;
    run_end date;
BEGIN
    SELECT * INTO a FROM fixed_assets WHERE id = NEW.asset_id AND company_id = NEW.company_id;
    SELECT period_end INTO run_end FROM depreciation_runs WHERE id = NEW.run_id AND company_id = NEW.company_id;
    IF a.id IS NULL OR run_end IS NULL THEN
        RAISE EXCEPTION 'A charge names an asset and a run of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF a.status <> 'active' OR a.depreciation_starts_on > run_end THEN
        RAISE EXCEPTION 'Asset % is not depreciated for the month to %', a.code, run_end USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > asset_carrying_amount(NEW.company_id, NEW.asset_id) - a.residual_value THEN
        RAISE EXCEPTION 'Asset % would be depreciated below its residual value', a.code USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- And an impairment is no more than the carrying amount above the residual value, revaluations counted.
CREATE OR REPLACE FUNCTION asset_impairments_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    a record;
    room numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'An impairment is kept as it was posted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT company_id, status, acquired_on, residual_value INTO a
      FROM fixed_assets WHERE id = NEW.asset_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR a.status <> 'active' OR NEW.impaired_on < a.acquired_on THEN
        RAISE EXCEPTION 'An impairment is of an active asset of the company, on or after it was acquired' USING ERRCODE = 'check_violation';
    END IF;
    room := asset_carrying_amount(NEW.company_id, NEW.asset_id) - a.residual_value;
    IF NEW.amount > room THEN
        RAISE EXCEPTION 'An impairment is no more than the carrying amount left above the residual value (%)', room USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.entry_id
                     AND source_type = 'asset_impairment' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'An impairment names its own entry' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

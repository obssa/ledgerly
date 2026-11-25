-- Evenbooks core schema, part 42: fixed assets and depreciation (Module 9c).
--
-- The asset register: each asset with its cost (already in the ledger, from the bill or the opening balances), where it
-- is and who keeps it, and how it wears out (straight line over its useful life, or a reducing balance at an annual
-- rate, down to its residual value). A monthly depreciation run posts one entry for the month: each asset's charge, Dr
-- its depreciation expense and Cr its accumulated depreciation. Rules here:
--   1. An asset's accounts are the company's: a fixed asset account, an accumulated depreciation (contra asset)
--      account and an expense account; its location is one of the company's.
--   2. Once depreciated, how an asset wears out (cost, residual value, method, life or rate, start, accounts) does not
--      change; its name, category, location and keeper may.
--   3. One depreciation run per month end, posted by its own entry dated that day; runs and their charges are kept as
--      they are. An asset is never depreciated below its residual value, nor before it starts.

CREATE TABLE fixed_assets (
    id                      uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id              uuid          NOT NULL REFERENCES companies (id),
    code                    text          NOT NULL CHECK (code ~ '^[A-Za-z0-9._/-]{1,30}$'),
    name                    text          NOT NULL CHECK (length(trim(name)) BETWEEN 2 AND 160),
    category                text          CHECK (length(category) <= 60),
    location_id             uuid,
    custodian               text          CHECK (length(custodian) <= 120),
    asset_account_id        uuid          NOT NULL REFERENCES accounts (id),
    depreciation_account_id uuid          NOT NULL REFERENCES accounts (id),
    expense_account_id      uuid          NOT NULL REFERENCES accounts (id),
    acquired_on             date          NOT NULL,
    cost                    numeric(20,2) NOT NULL CHECK (cost > 0),
    residual_value          numeric(20,2) NOT NULL DEFAULT 0 CHECK (residual_value >= 0),
    method                  text          NOT NULL CHECK (method IN ('straight_line', 'reducing_balance')),
    useful_life_months      integer       CHECK (useful_life_months BETWEEN 1 AND 1200),
    annual_rate             numeric(9,6)  CHECK (annual_rate > 0 AND annual_rate < 1),
    depreciation_starts_on  date          NOT NULL,
    opening_depreciation    numeric(20,2) NOT NULL DEFAULT 0 CHECK (opening_depreciation >= 0),   -- before the company's books began in Evenbooks
    status                  text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disposed')),
    source_document_id      uuid          REFERENCES documents (id),
    created_by              uuid          NOT NULL REFERENCES users (id),
    created_at              timestamptz   NOT NULL DEFAULT now(),
    updated_at              timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, code),
    CHECK (residual_value + opening_depreciation <= cost),
    CHECK (depreciation_starts_on >= acquired_on),
    CHECK ((method = 'straight_line' AND useful_life_months IS NOT NULL) OR (method = 'reducing_balance' AND annual_rate IS NOT NULL))
);

CREATE TABLE depreciation_runs (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    period_end       date          NOT NULL,
    journal_entry_id uuid          NOT NULL,
    total            numeric(20,2) NOT NULL CHECK (total > 0),
    created_by       uuid          NOT NULL REFERENCES users (id),
    created_at       timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, period_end),
    CHECK (period_end = (date_trunc('month', period_end) + interval '1 month - 1 day')::date),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
);

CREATE TABLE asset_depreciation (
    company_id uuid          NOT NULL REFERENCES companies (id),
    run_id     uuid          NOT NULL REFERENCES depreciation_runs (id),
    asset_id   uuid          NOT NULL REFERENCES fixed_assets (id),
    amount     numeric(20,2) NOT NULL CHECK (amount > 0),
    PRIMARY KEY (run_id, asset_id)
);
CREATE INDEX asset_depreciation_asset_idx ON asset_depreciation (company_id, asset_id);

CREATE FUNCTION fixed_assets_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'INSERT' AND app_user_id() IS NOT NULL AND NEW.created_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'An asset is registered by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.asset_account_id AND company_id = NEW.company_id AND type = 'asset' AND subtype = 'fixed_asset')
       OR NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.depreciation_account_id AND company_id = NEW.company_id AND type = 'asset' AND subtype = 'contra_asset')
       OR NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.expense_account_id AND company_id = NEW.company_id AND type = 'expense') THEN
        RAISE EXCEPTION 'An asset uses the company''s fixed asset, accumulated depreciation and expense accounts' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT dimension_belongs(NEW.company_id, 'location', NEW.location_id) THEN
        RAISE EXCEPTION 'An asset is at one of the company''s locations' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.company_id, NEW.created_by, NEW.created_at) IS DISTINCT FROM (OLD.company_id, OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'An asset keeps its company and who registered it' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM asset_depreciation WHERE company_id = OLD.company_id AND asset_id = OLD.id)
           AND (NEW.cost, NEW.residual_value, NEW.method, NEW.useful_life_months, NEW.annual_rate, NEW.depreciation_starts_on, NEW.acquired_on,
                NEW.opening_depreciation, NEW.asset_account_id, NEW.depreciation_account_id, NEW.expense_account_id)
               IS DISTINCT FROM (OLD.cost, OLD.residual_value, OLD.method, OLD.useful_life_months, OLD.annual_rate, OLD.depreciation_starts_on, OLD.acquired_on,
                OLD.opening_depreciation, OLD.asset_account_id, OLD.depreciation_account_id, OLD.expense_account_id) THEN
            RAISE EXCEPTION 'Asset % has been depreciated: how it wears out stays as it was', OLD.code USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER fixed_assets_valid BEFORE INSERT OR UPDATE ON fixed_assets
    FOR EACH ROW EXECUTE FUNCTION fixed_assets_valid();

CREATE FUNCTION depreciation_runs_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF app_user_id() IS NOT NULL AND NEW.created_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A depreciation run is made by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id
                     AND source_type = 'depreciation' AND source_id = NEW.id AND entry_date = NEW.period_end) THEN
        RAISE EXCEPTION 'A depreciation run names its own entry, dated the month end' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER depreciation_runs_valid BEFORE INSERT ON depreciation_runs
    FOR EACH ROW EXECUTE FUNCTION depreciation_runs_valid();

CREATE FUNCTION asset_depreciation_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    a record;
    run_end date;
    so_far numeric;
BEGIN
    SELECT * INTO a FROM fixed_assets WHERE id = NEW.asset_id AND company_id = NEW.company_id;
    SELECT period_end INTO run_end FROM depreciation_runs WHERE id = NEW.run_id AND company_id = NEW.company_id;
    IF a.id IS NULL OR run_end IS NULL THEN
        RAISE EXCEPTION 'A charge names an asset and a run of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF a.status <> 'active' OR a.depreciation_starts_on > run_end THEN
        RAISE EXCEPTION 'Asset % is not depreciated for the month to %', a.code, run_end USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(amount), 0) INTO so_far FROM asset_depreciation WHERE company_id = NEW.company_id AND asset_id = NEW.asset_id;
    IF a.opening_depreciation + so_far + NEW.amount > a.cost - a.residual_value THEN
        RAISE EXCEPTION 'Asset % would be depreciated below its residual value', a.code USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER asset_depreciation_valid BEFORE INSERT ON asset_depreciation
    FOR EACH ROW EXECUTE FUNCTION asset_depreciation_valid();

CREATE FUNCTION journal_entries_depreciation_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    -- The run is written right after its entry, in the same transaction: the entry names the month end it is for.
    IF NEW.source_type = 'depreciation' AND NEW.entry_date <> (date_trunc('month', NEW.entry_date) + interval '1 month - 1 day')::date THEN
        RAISE EXCEPTION 'A depreciation entry is dated a month end' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_depreciation_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_depreciation_valid();

ALTER TABLE fixed_assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE depreciation_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE asset_depreciation ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON fixed_assets
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
CREATE POLICY tenant_isolation ON depreciation_runs
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
CREATE POLICY tenant_isolation ON asset_depreciation
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON fixed_assets, depreciation_runs, asset_depreciation FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON fixed_assets TO {{app_role}};
GRANT SELECT, INSERT ON depreciation_runs, asset_depreciation TO {{app_role}};

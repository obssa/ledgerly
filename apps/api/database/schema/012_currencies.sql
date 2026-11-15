-- Ledgerly core schema, part 12: foreign currencies and exchange rates (Module 1).
--
-- company_currencies: the foreign currencies a company works in. A document in another currency than the base
-- currency must use one of them.
-- exchange_rates: one rate per currency and day, as base-currency units for one unit of the foreign currency
-- (1 USD = 134.523333 ETB is stored as 134.523333). A document copies the rate it used onto itself (fx_rate), so
-- correcting a day's rate later never restates what was already posted. Rates are corrected, never deleted, and
-- every change is in the audit log.

CREATE TABLE company_currencies (
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    currency    char(3)     NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    is_enabled  boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, currency)
);

CREATE TABLE exchange_rates (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    currency    char(3)       NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    rate_date   date          NOT NULL,
    rate        numeric(18,6) NOT NULL CHECK (rate > 0),
    source      text          NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'central_bank')),
    created_by  uuid          REFERENCES users (id),
    created_at  timestamptz   NOT NULL DEFAULT now(),
    updated_at  timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, currency, rate_date)
);
-- "The rate on or before this date": the newest row at or below the date, per currency.
CREATE INDEX exchange_rates_lookup ON exchange_rates (company_id, currency, rate_date DESC);

-- A foreign currency is never the base currency: a rate of the base currency against itself is always 1.
-- SECURITY DEFINER reads the company row whatever the caller's row-level security context.
CREATE FUNCTION currencies_not_base() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.currency = (SELECT base_currency FROM companies WHERE id = NEW.company_id) THEN
        RAISE EXCEPTION '% is the base currency of this company, not a foreign currency', NEW.currency
            USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.currency) IS DISTINCT FROM (OLD.company_id, OLD.currency) THEN
        RAISE EXCEPTION 'The company and currency of a row cannot change' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION currencies_not_base() FROM PUBLIC;

CREATE TRIGGER company_currencies_not_base BEFORE INSERT OR UPDATE ON company_currencies
    FOR EACH ROW EXECUTE FUNCTION currencies_not_base();
CREATE TRIGGER exchange_rates_not_base BEFORE INSERT OR UPDATE ON exchange_rates
    FOR EACH ROW EXECUTE FUNCTION currencies_not_base();

ALTER TABLE company_currencies ENABLE ROW LEVEL SECURITY;
ALTER TABLE exchange_rates ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON company_currencies
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
CREATE POLICY tenant_isolation ON exchange_rates
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));

-- Turned off, not removed; corrected, not deleted.
GRANT SELECT, INSERT, UPDATE ON company_currencies, exchange_rates TO {{app_role}};

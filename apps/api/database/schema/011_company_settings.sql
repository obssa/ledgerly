-- Evenbooks core schema, part 11: company profile and the settings the books are built on (Module 1).
--
-- 1. The profile gains its legal form and postal address.
-- 2. The foundation of the books (country, base currency, framework, calendar, fiscal year start, conversion date)
--    can change only while nothing is posted. Once one journal entry exists every amount, period and opening
--    balance depends on them; a later change would silently restate the books. This holds for every caller,
--    the owner role included: a company that needs another foundation starts new books.

ALTER TABLE companies
    ADD COLUMN legal_form    text CHECK (legal_form IN ('sole_proprietor', 'partnership', 'private_limited', 'share_company',
                                                       'cooperative', 'non_profit', 'branch_of_foreign', 'public_body', 'other')),
    ADD COLUMN address_line1 text CHECK (length(address_line1) <= 200),
    ADD COLUMN address_line2 text CHECK (length(address_line2) <= 200),
    ADD COLUMN city          text CHECK (length(city) <= 100),
    ADD COLUMN region        text CHECK (length(region) <= 100),
    ADD COLUMN postal_code   text CHECK (length(postal_code) <= 20);

-- SECURITY DEFINER: the check must see every journal entry of the company whatever the caller's row-level
-- security context, so a caller cannot hide postings from it. The exclusive period lock (002) waits for a posting
-- in flight, which holds the shared one, so a posting cannot commit between the check and the change.
CREATE FUNCTION companies_keep_books_foundation() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF (NEW.country_code, NEW.base_currency, NEW.framework, NEW.calendar, NEW.fiscal_year_start_month, NEW.conversion_date)
       IS NOT DISTINCT FROM
       (OLD.country_code, OLD.base_currency, OLD.framework, OLD.calendar, OLD.fiscal_year_start_month, OLD.conversion_date) THEN
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(ledger_period_lock_key(NEW.id));
    IF EXISTS (SELECT 1 FROM journal_entries WHERE company_id = OLD.id) THEN
        RAISE EXCEPTION 'The country, base currency, framework, calendar, fiscal year start and conversion date cannot change once anything is posted'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

REVOKE ALL ON FUNCTION companies_keep_books_foundation() FROM PUBLIC;

CREATE TRIGGER companies_keep_books_foundation BEFORE UPDATE ON companies
    FOR EACH ROW EXECUTE FUNCTION companies_keep_books_foundation();

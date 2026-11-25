-- Evenbooks core schema, part 30: petty cash floats and cash counts (Module 5).
--
-- A cash box or petty cash account can keep a float: the amount it is topped up to. Its cash is counted now and then,
-- by a named person. Rules here:
--   1. Only a cash account keeps a float, and it is above zero.
--   2. A cash count belongs to one of the company's cash accounts in the company's currency, says who counted, and never
--      changes or goes away.
--   3. After a count, the books say what was counted: the account's balance on the day of the count equals the amount
--      counted. A difference is posted first (to cash over and short), and the count names that entry; a count with no
--      difference names none.

ALTER TABLE accounts
    ADD COLUMN float_amount numeric(20,2) CHECK (float_amount > 0),
    ADD CONSTRAINT accounts_float_only_for_cash CHECK (float_amount IS NULL OR subtype = 'cash') NOT VALID;

CREATE TABLE cash_counts (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    account_id       uuid          NOT NULL REFERENCES accounts (id),
    counted_on       date          NOT NULL,
    counted_by_name  text          NOT NULL CHECK (length(trim(counted_by_name)) BETWEEN 2 AND 120),
    recorded_by      uuid          REFERENCES users (id),
    denominations    jsonb         NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(denominations) = 'object'),
    counted          numeric(20,2) NOT NULL CHECK (counted >= 0),
    book_balance     numeric(20,2) NOT NULL,
    difference       numeric(20,2) NOT NULL,
    journal_entry_id uuid,
    note             text          CHECK (length(note) <= 300),
    created_at       timestamptz   NOT NULL DEFAULT now(),
    CHECK (difference = counted - book_balance),
    CHECK ((difference = 0) = (journal_entry_id IS NULL)),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
);
CREATE INDEX cash_counts_account_idx ON cash_counts (company_id, account_id, counted_on);

CREATE FUNCTION cash_counts_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    acc record;
    books numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A cash count is never changed or removed: count again' USING ERRCODE = 'check_violation';
    END IF;
    SELECT a.company_id, a.subtype, a.system_key, nullif(a.currency, c.base_currency) AS foreign_currency INTO acc
      FROM accounts a JOIN companies c ON c.id = a.company_id WHERE a.id = NEW.account_id;
    IF acc.company_id IS DISTINCT FROM NEW.company_id OR coalesce(acc.subtype, '') <> 'cash'
       OR acc.system_key IS NOT DISTINCT FROM 'undeposited_funds' OR acc.foreign_currency IS NOT NULL THEN
        RAISE EXCEPTION 'Cash is counted in one of the company''s cash accounts, in its currency' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.journal_entry_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM journal_lines WHERE company_id = NEW.company_id AND journal_entry_id = NEW.journal_entry_id
           AND account_id = NEW.account_id AND entry_date = NEW.counted_on AND debit - credit = NEW.difference) THEN
        RAISE EXCEPTION 'A count''s entry puts its difference on the account, on the day of the count' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(debit - credit), 0) INTO books FROM journal_lines
     WHERE company_id = NEW.company_id AND account_id = NEW.account_id AND entry_date <= NEW.counted_on;
    IF books <> NEW.counted THEN
        RAISE EXCEPTION 'After the count the books must hold what was counted (% in the books, % counted)', books, NEW.counted
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER cash_counts_valid BEFORE INSERT OR UPDATE OR DELETE ON cash_counts
    FOR EACH ROW EXECUTE FUNCTION cash_counts_valid();

ALTER TABLE cash_counts ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON cash_counts
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON cash_counts FROM {{app_role}};
GRANT SELECT, INSERT ON cash_counts TO {{app_role}};

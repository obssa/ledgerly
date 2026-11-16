-- Ledgerly core schema, part 26: bank, wallet and cash accounts (Module 5).
--
-- A money account is a ledger account of subtype bank, mobile_money or cash, in the company's currency or a foreign one.
-- This part adds where it is (the bank, the account number, the branch) and one rule for every account:
--   an account that has entries keeps its type, subtype and currency. Changing them would rewrite what every past entry
--   meant (a bank account turning into an expense, a birr account turning into dollars). Rename it, archive it, or open
--   a new one instead.

ALTER TABLE accounts
    ADD COLUMN bank_details jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD CONSTRAINT accounts_bank_details_is_object CHECK (jsonb_typeof(bank_details) = 'object');

CREATE FUNCTION accounts_keep_meaning() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF (NEW.type, NEW.subtype, NEW.currency) IS DISTINCT FROM (OLD.type, OLD.subtype, OLD.currency)
       AND EXISTS (SELECT 1 FROM journal_lines WHERE company_id = OLD.company_id AND account_id = OLD.id) THEN
        RAISE EXCEPTION 'Account % has entries: its type, kind and currency stay. Open a new account instead.', OLD.code
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER accounts_keep_meaning BEFORE UPDATE OF type, subtype, currency ON accounts
    FOR EACH ROW EXECUTE FUNCTION accounts_keep_meaning();

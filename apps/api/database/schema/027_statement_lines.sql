-- Ledgerly core schema, part 27: bank statement lines and their review (Module 5).
--
-- An imported statement line (bank_transactions) is evidence: what the bank says happened. It is reviewed by matching it
-- to an entry already in the books, categorising it (a new entry), or excluding it with a reason. Rules here:
--   1. A statement line belongs to one of the company's money accounts (bank, wallet, cash).
--   2. What the bank said (date, amount, description, account, reference) never changes after import.
--   3. A matched or categorised line names one journal line on the same account, for the same amount (in the account's
--      currency); an imported or excluded line names none. One journal line explains at most one statement line.
--   4. Statement lines are never deleted; a wrong one is excluded, with its reason.

ALTER TABLE bank_transactions
    ADD COLUMN reference   text CHECK (length(reference) <= 100),
    ADD COLUMN note        text CHECK (length(note) <= 300),   -- why it was excluded, or what it was
    ADD COLUMN imported_by uuid REFERENCES users (id),
    ADD CONSTRAINT bank_transactions_explained CHECK ((status IN ('matched', 'categorised', 'reconciled')) = (journal_line_id IS NOT NULL)),
    ADD CONSTRAINT bank_transactions_excluded_says_why CHECK (status <> 'excluded' OR note IS NOT NULL);

CREATE UNIQUE INDEX bank_transactions_one_line_each ON bank_transactions (company_id, journal_line_id) WHERE journal_line_id IS NOT NULL;

CREATE FUNCTION bank_transactions_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    acc record;
    jl record;
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.account_id, NEW.posted_on, NEW.amount, NEW.description, NEW.external_id, NEW.reference)
        IS DISTINCT FROM (OLD.company_id, OLD.account_id, OLD.posted_on, OLD.amount, OLD.description, OLD.external_id, OLD.reference) THEN
        RAISE EXCEPTION 'What the bank said does not change: exclude the line and import it again' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.journal_line_id IS DISTINCT FROM OLD.journal_line_id AND 'imported' NOT IN (OLD.status, NEW.status) THEN
        RAISE EXCEPTION 'Undo the match before explaining the line another way' USING ERRCODE = 'check_violation';
    END IF;
    SELECT a.company_id, a.subtype, nullif(a.currency, c.base_currency) AS foreign_currency INTO acc
      FROM accounts a JOIN companies c ON c.id = a.company_id WHERE a.id = NEW.account_id;
    IF acc.company_id IS DISTINCT FROM NEW.company_id OR coalesce(acc.subtype, '') NOT IN ('bank', 'mobile_money', 'cash') THEN
        RAISE EXCEPTION 'A statement line belongs to one of the company''s bank, wallet or cash accounts' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.journal_line_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.journal_line_id IS DISTINCT FROM OLD.journal_line_id) THEN
        SELECT account_id, CASE WHEN acc.foreign_currency IS NULL THEN debit - credit ELSE amount_txn END AS amount INTO jl
          FROM journal_lines WHERE company_id = NEW.company_id AND id = NEW.journal_line_id;
        IF NOT FOUND OR jl.account_id <> NEW.account_id OR jl.amount <> NEW.amount THEN
            RAISE EXCEPTION 'A statement line is explained by an entry on the same account for the same amount' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER bank_transactions_valid BEFORE INSERT OR UPDATE ON bank_transactions
    FOR EACH ROW EXECUTE FUNCTION bank_transactions_valid();

REVOKE DELETE ON bank_transactions FROM {{app_role}};

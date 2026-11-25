-- Evenbooks core schema, part 70: sales receipts, the cash sale (Module 2).
--
-- A sales receipt is a sale paid at once, in cash, by card, transfer or wallet: a shop sale, a walk-in customer. It
-- is a document of its own (type 'sales_receipt', numbered SR-) that posts in one entry, Dr the account the money went
-- into, Cr income and the tax payable (and, for stock items, Dr Cost of goods sold, Cr Inventory), so it never passes
-- through accounts receivable. It is the sales side of 021's expense, and keeps that document's two columns:
-- paid_from_account_id names the money account (here the one the money went into) and payment_method how it was paid.
-- Rules:
--   1. An expense or a sales receipt names its money account, one of the company's own bank, cash or mobile-money
--      accounts, and how it was paid. Other documents name no such account.
--   2. A posted sales receipt leaves nothing to pay, like an expense.
--   3. A sales receipt is for a customer (021's rule, unchanged).

ALTER TABLE documents DROP CONSTRAINT documents_expense_names_its_account,
    ADD CONSTRAINT documents_expense_names_its_account CHECK ((type IN ('expense', 'sales_receipt')) = (paid_from_account_id IS NOT NULL));
ALTER TABLE documents DROP CONSTRAINT documents_expense_names_its_method,
    ADD CONSTRAINT documents_expense_names_its_method CHECK ((type IN ('expense', 'sales_receipt')) = (payment_method IS NOT NULL));
ALTER TABLE documents DROP CONSTRAINT documents_expense_leaves_nothing_due,
    ADD CONSTRAINT documents_expense_leaves_nothing_due CHECK (type NOT IN ('expense', 'sales_receipt') OR status NOT IN ('posted', 'part_paid', 'paid') OR amount_due = 0);

CREATE OR REPLACE FUNCTION documents_expense_account_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.paid_from_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM accounts WHERE id = NEW.paid_from_account_id AND company_id = NEW.company_id
           AND subtype IN ('bank', 'cash', 'mobile_money')) THEN
        RAISE EXCEPTION 'An expense is paid from, and a sales receipt paid into, one of the company''s own bank, cash or mobile-money accounts'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

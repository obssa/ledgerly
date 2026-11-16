-- Ledgerly core schema, part 21: expenses paid on the spot (Module 3).
--
-- An expense is a supplier's receipt paid at once, by cash, card, bank or wallet: fuel, utilities, small purchases.
-- It is a document of its own (type 'expense', numbered EXP-) that posts in one entry, Dr cost and input VAT,
-- Cr the account it was paid from, so it never passes through accounts payable. Rules here:
--   1. An expense names the account it was paid from, one of the company's own bank, cash or mobile-money accounts,
--      and how it was paid. Other documents name no such account.
--   2. A posted expense leaves nothing to pay.
--   3. An expense names a supplier (019's rule, extended).

ALTER TABLE documents DROP CONSTRAINT documents_type_check,
    ADD CONSTRAINT documents_type_check CHECK (type IN ('quote', 'sales_order', 'invoice', 'credit_note',
                                                        'purchase_order', 'bill', 'vendor_credit', 'sales_receipt', 'expense'));
ALTER TABLE document_numbering DROP CONSTRAINT document_numbering_doc_type_check,
    ADD CONSTRAINT document_numbering_doc_type_check CHECK (doc_type IN ('invoice', 'credit_note', 'bill', 'vendor_credit',
                                                                         'sales_receipt', 'quote', 'sales_order', 'purchase_order', 'expense'));

ALTER TABLE documents
    ADD COLUMN paid_from_account_id uuid REFERENCES accounts (id),
    ADD COLUMN payment_method       text CHECK (payment_method IN ('cash', 'bank_transfer', 'cheque', 'card', 'mobile_money', 'other')),
    ADD CONSTRAINT documents_expense_names_its_account CHECK ((type = 'expense') = (paid_from_account_id IS NOT NULL)),
    ADD CONSTRAINT documents_expense_names_its_method CHECK ((type = 'expense') = (payment_method IS NOT NULL)),
    ADD CONSTRAINT documents_expense_leaves_nothing_due CHECK (type <> 'expense' OR status NOT IN ('posted', 'part_paid', 'paid') OR amount_due = 0);

CREATE FUNCTION documents_expense_account_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.paid_from_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM accounts WHERE id = NEW.paid_from_account_id AND company_id = NEW.company_id
           AND subtype IN ('bank', 'cash', 'mobile_money')) THEN
        RAISE EXCEPTION 'An expense is paid from one of the company''s own bank, cash or mobile-money accounts'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_expense_account_valid BEFORE INSERT OR UPDATE OF paid_from_account_id, company_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_expense_account_valid();

CREATE OR REPLACE FUNCTION documents_contact_role() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    c record;
BEGIN
    SELECT is_customer, is_vendor INTO c FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'The contact of % % is not one of the company''s contacts', NEW.type, coalesce(NEW.number, '(draft)')
            USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.type IN ('bill', 'vendor_credit', 'purchase_order', 'expense') AND NOT c.is_vendor THEN
        RAISE EXCEPTION 'A % is for a supplier', NEW.type USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.type IN ('invoice', 'credit_note', 'quote', 'sales_order', 'sales_receipt') AND NOT c.is_customer THEN
        RAISE EXCEPTION 'A % is for a customer', NEW.type USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

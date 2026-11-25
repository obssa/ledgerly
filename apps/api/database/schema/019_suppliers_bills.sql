-- Evenbooks core schema, part 19: suppliers and bills (Module 3).
--
-- A contact is a customer, a supplier (is_vendor) or both. The supplier side adds where to pay them (bank or
-- mobile-money details), the expense account their bills usually go to, and whether withholding tax applies
-- (an exemption names its certificate). A bill keeps the supplier's own invoice number as its reference; its own
-- number comes from the company's numbering when it is posted, like an invoice.
--
-- Rules here:
--   - sales documents name a customer and purchase documents a supplier;
--   - a supplier's default expense account is one of the company's own expense or asset accounts.

ALTER TABLE contacts
    ADD COLUMN payout                     jsonb   NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN default_expense_account_id uuid    REFERENCES accounts (id),
    ADD COLUMN withholding_exempt         boolean NOT NULL DEFAULT false,
    ADD COLUMN withholding_exempt_reason  text    CHECK (length(withholding_exempt_reason) <= 300),
    ADD CONSTRAINT contacts_payout_is_object CHECK (jsonb_typeof(payout) = 'object'),
    ADD CONSTRAINT contacts_withholding_exemption_has_reason CHECK (NOT withholding_exempt OR withholding_exempt_reason IS NOT NULL);

ALTER TABLE documents
    ADD COLUMN reference text CHECK (length(reference) <= 60);   -- the supplier's own invoice number on a bill

CREATE INDEX documents_bill_reference_idx ON documents (company_id, contact_id, reference) WHERE type = 'bill';

CREATE FUNCTION contacts_default_account_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.default_expense_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM accounts WHERE id = NEW.default_expense_account_id AND company_id = NEW.company_id AND type IN ('expense', 'asset')) THEN
        RAISE EXCEPTION 'A supplier''s default account is one of the company''s own expense or asset accounts' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER contacts_default_account_valid BEFORE INSERT OR UPDATE OF default_expense_account_id, company_id ON contacts
    FOR EACH ROW EXECUTE FUNCTION contacts_default_account_valid();

CREATE FUNCTION documents_contact_role() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    c record;
BEGIN
    SELECT is_customer, is_vendor INTO c FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'The contact of % % is not one of the company''s contacts', NEW.type, coalesce(NEW.number, '(draft)')
            USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.type IN ('bill', 'vendor_credit', 'purchase_order') AND NOT c.is_vendor THEN
        RAISE EXCEPTION 'A % is for a supplier', NEW.type USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.type IN ('invoice', 'credit_note', 'quote', 'sales_order', 'sales_receipt') AND NOT c.is_customer THEN
        RAISE EXCEPTION 'A % is for a customer', NEW.type USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_contact_role BEFORE INSERT OR UPDATE OF contact_id, type ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_contact_role();

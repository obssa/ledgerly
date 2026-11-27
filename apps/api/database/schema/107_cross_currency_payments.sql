-- 107: a foreign-currency payment through an account in the company's currency (QuickBooks: a dollar invoice paid
-- into a birr bank account, or a dollar bill paid from it). The account is in the payment's currency, as before, or in
-- the company's; never a third one. When it is the company's, account_amount is what the account received or paid for
-- the whole payment, in the company's currency: the bank's line is exactly that, and each invoice or bill is settled at
-- its own rate, the difference a realised exchange gain or loss. Recorded payments stay frozen (029 compares every
-- column), so account_amount never changes either.

ALTER TABLE payments
    ADD COLUMN account_amount numeric(20,2) CHECK (account_amount IS NULL OR account_amount > 0);

CREATE FUNCTION payments_account_currency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    base char(3);
    held char(3);
BEGIN
    SELECT base_currency INTO base FROM companies WHERE id = NEW.company_id;
    SELECT coalesce(currency, base) INTO held FROM accounts WHERE id = NEW.account_id AND company_id = NEW.company_id;
    IF held IS NULL THEN
        RETURN NEW;   -- not the company's account: the foreign key and the money-account rules refuse it
    END IF;
    IF held <> NEW.currency AND held <> base THEN
        RAISE EXCEPTION 'A % payment goes through an account in % or in the company''s currency, not %', NEW.currency, NEW.currency, held
            USING ERRCODE = 'check_violation';
    END IF;
    IF held = NEW.currency AND NEW.account_amount IS NOT NULL THEN
        RAISE EXCEPTION 'An account in the payment''s own currency moves the payment''s amount; no other amount is kept'
            USING ERRCODE = 'check_violation';
    END IF;
    IF held <> NEW.currency AND NEW.account_amount IS NULL THEN
        RAISE EXCEPTION 'A % payment through a % account keeps what the account moved, in %', NEW.currency, held, held
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER payments_account_currency BEFORE INSERT ON payments
    FOR EACH ROW EXECUTE FUNCTION payments_account_currency();

-- A scheduled payment goes out of an account in its own currency or in the company's (103 asked for its own only).
CREATE OR REPLACE FUNCTION scheduled_payments_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'scheduled' OR NEW.reminded_on IS NOT NULL THEN
            RAISE EXCEPTION 'A payment is scheduled before anything else happens to it' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id AND is_vendor) THEN
            RAISE EXCEPTION 'A scheduled payment is to one of the company''s suppliers' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        IF NEW.company_id <> OLD.company_id OR NEW.contact_id <> OLD.contact_id OR NEW.currency <> OLD.currency
           OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
            RAISE EXCEPTION 'A scheduled payment keeps its supplier and currency; cancel it and schedule another' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status <> 'scheduled' AND (to_jsonb(NEW) - 'updated_at') <> (to_jsonb(OLD) - 'updated_at') THEN
            RAISE EXCEPTION 'A scheduled payment that was % stays as it is', OLD.status USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.reminded_on IS NOT NULL AND NEW.reminded_on IS DISTINCT FROM OLD.reminded_on THEN
            RAISE EXCEPTION 'The reminder of a scheduled payment goes once' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'paid' AND OLD.status = 'scheduled' AND NOT EXISTS (
            SELECT 1 FROM payments p
             WHERE p.id = NEW.payment_id AND p.company_id = NEW.company_id AND p.direction = 'out' AND p.contact_id = NEW.contact_id
               AND p.account_id = NEW.account_id AND p.currency = NEW.currency AND p.payment_date = NEW.closed_on) THEN
            RAISE EXCEPTION 'A scheduled payment that was paid names the payment it became' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'scheduled' AND NEW.payment_id IS NOT NULL THEN
            RAISE EXCEPTION 'A payment still scheduled has not become a payment' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NEW.status = 'scheduled' AND NOT EXISTS (
        SELECT 1 FROM accounts a JOIN companies c ON c.id = a.company_id
         WHERE a.id = NEW.account_id AND a.company_id = NEW.company_id AND a.is_active
           AND a.subtype IN ('bank', 'cash', 'mobile_money') AND coalesce(a.currency, c.base_currency) IN (NEW.currency, c.base_currency)) THEN
        RAISE EXCEPTION 'A scheduled payment goes out of one of the company''s bank, cash or mobile-money accounts, in its currency or the company''s'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

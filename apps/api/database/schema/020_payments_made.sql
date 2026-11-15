-- Ledgerly core schema, part 20: payments made to suppliers (Module 3).
--
-- 016 covers payments received. This part extends the same rules to payments out:
--   1. A payment in settles invoices of its customer; a payment out settles bills of its supplier. Same company, same
--      contact, same currency, and never more than the payment brought (amount + tax withheld), as before.
--   2. A payment in names a customer and a payment out a supplier. Tax is never withheld from a supplier who holds a
--      withholding exemption certificate.
--   3. The withholding certificate given to the supplier is kept on the payment; like every other field it never
--      changes once recorded (016 payments_frozen).

ALTER TABLE payments
    ADD COLUMN withholding_certificate text CHECK (length(withholding_certificate) <= 60);

CREATE OR REPLACE FUNCTION payment_allocations_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    pay record;
    doc record;
    settles text;
BEGIN
    SELECT company_id, direction, contact_id, currency, amount, withholding, status INTO pay FROM payments WHERE id = NEW.payment_id FOR UPDATE;
    SELECT company_id, contact_id, currency, type INTO doc FROM documents WHERE id = NEW.document_id;
    IF pay.company_id IS DISTINCT FROM NEW.company_id OR doc.company_id IS DISTINCT FROM NEW.company_id THEN
        RAISE EXCEPTION 'An allocation, its payment and its document belong to one company' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF pay.status <> 'posted' THEN
        RAISE EXCEPTION 'A reversed payment settles nothing' USING ERRCODE = 'check_violation';
    END IF;
    settles := CASE pay.direction WHEN 'in' THEN 'invoice' ELSE 'bill' END;
    IF doc.type <> settles OR doc.contact_id IS DISTINCT FROM pay.contact_id OR doc.currency <> pay.currency THEN
        RAISE EXCEPTION 'A payment received settles invoices, a payment made settles bills, of its own contact and currency'
            USING ERRCODE = 'check_violation';
    END IF;
    IF (SELECT coalesce(sum(amount), 0) FROM payment_allocations WHERE payment_id = NEW.payment_id) + NEW.amount
       > pay.amount + pay.withholding THEN
        RAISE EXCEPTION 'A payment cannot settle more than it brought in' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE FUNCTION payments_contact_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    c record;
BEGIN
    IF NEW.contact_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT is_customer, is_vendor, withholding_exempt INTO c FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'A payment names one of the company''s own contacts' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF (NEW.direction = 'in' AND NOT c.is_customer) OR (NEW.direction = 'out' AND NOT c.is_vendor) THEN
        RAISE EXCEPTION 'A payment received is from a customer, a payment made is to a supplier' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.direction = 'out' AND NEW.withholding > 0 AND c.withholding_exempt THEN
        RAISE EXCEPTION 'This supplier holds a withholding exemption certificate: no tax is withheld' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION payments_contact_valid() FROM PUBLIC;

CREATE TRIGGER payments_contact_valid BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payments_contact_valid();

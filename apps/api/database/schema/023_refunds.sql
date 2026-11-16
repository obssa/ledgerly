-- Ledgerly core schema, part 23: refunds (Modules 2 and 3).
--
-- A refund gives back credit: money paid out to a customer for what they hold (a credit note's credit left, or the
-- unused part of their payment), or money a supplier pays back (a supplier credit's credit left, or the unused part of
-- an advance). It is a payment of kind 'refund', recorded and reversed like any other (016 keeps it frozen).
--
--   1. A payment received is from a customer and a payment made to a supplier; a refund out is to a customer and a
--      refund in from a supplier. Nothing is withheld on a refund.
--   2. A refund settles credits, never invoices or bills: a refund out settles credit notes, a refund in supplier
--      credits, of its own contact and currency, never more than the credit left (006 lowers it as for any allocation).
--   3. A refund of the unused part of a payment names that payment: same company, contact and currency, the opposite
--      direction, still standing, and never more than is still unused. What a payment brought is shared between its
--      allocations and the refunds of it.

ALTER TABLE payments
    ADD COLUMN kind               text NOT NULL DEFAULT 'payment' CHECK (kind IN ('payment', 'refund')),
    ADD COLUMN refunds_payment_id uuid REFERENCES payments (id),
    ADD CONSTRAINT payments_only_refunds_refund CHECK (kind = 'refund' OR refunds_payment_id IS NULL),
    ADD CONSTRAINT payments_nothing_withheld_on_a_refund CHECK (kind = 'payment' OR withholding = 0);

CREATE OR REPLACE FUNCTION payments_contact_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    c record;
    customer_side boolean;
BEGIN
    IF NEW.contact_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT is_customer, is_vendor, withholding_exempt INTO c FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'A payment names one of the company''s own contacts' USING ERRCODE = 'foreign_key_violation';
    END IF;
    -- Money from a customer, or back to one; money to a supplier, or back from one.
    customer_side := (NEW.direction = 'in') = (NEW.kind = 'payment');
    IF (customer_side AND NOT c.is_customer) OR (NOT customer_side AND NOT c.is_vendor) THEN
        RAISE EXCEPTION 'Payments received and refunds paid out are for customers; payments made and refunds received are for suppliers'
            USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.direction = 'out' AND NEW.kind = 'payment' AND NEW.withholding > 0 AND c.withholding_exempt THEN
        RAISE EXCEPTION 'This supplier holds a withholding exemption certificate: no tax is withheld' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- What a payment brought and has not used: its amount and tax withheld, less its allocations and its standing refunds.
CREATE FUNCTION payment_unused(p_payment_id uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT p.amount + p.withholding
         - coalesce((SELECT sum(a.amount) FROM payment_allocations a WHERE a.payment_id = p.id), 0)
         - coalesce((SELECT sum(r.amount) FROM payments r WHERE r.refunds_payment_id = p.id AND r.status = 'posted'), 0)
      FROM payments p WHERE p.id = p_payment_id
$$;
REVOKE ALL ON FUNCTION payment_unused(uuid) FROM PUBLIC;

CREATE FUNCTION payments_refund_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    orig record;
BEGIN
    IF NEW.refunds_payment_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT company_id, direction, kind, contact_id, currency, status INTO orig FROM payments WHERE id = NEW.refunds_payment_id FOR UPDATE;
    IF orig.company_id IS DISTINCT FROM NEW.company_id OR orig.kind <> 'payment' OR orig.direction = NEW.direction
       OR orig.contact_id IS DISTINCT FROM NEW.contact_id OR orig.currency <> NEW.currency THEN
        RAISE EXCEPTION 'A refund gives back part of a payment of the same contact and currency, the other way round'
            USING ERRCODE = 'check_violation';
    END IF;
    IF orig.status <> 'posted' THEN
        RAISE EXCEPTION 'A reversed payment has nothing to refund' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > payment_unused(NEW.refunds_payment_id) THEN
        RAISE EXCEPTION 'A refund cannot give back more than is still unused of the payment' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION payments_refund_valid() FROM PUBLIC;
CREATE TRIGGER payments_refund_valid BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payments_refund_valid();

CREATE OR REPLACE FUNCTION payment_allocations_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    pay record;
    doc record;
    settles text;
BEGIN
    SELECT company_id, direction, kind, contact_id, currency, amount, withholding, status INTO pay FROM payments WHERE id = NEW.payment_id FOR UPDATE;
    SELECT company_id, contact_id, currency, type INTO doc FROM documents WHERE id = NEW.document_id;
    IF pay.company_id IS DISTINCT FROM NEW.company_id OR doc.company_id IS DISTINCT FROM NEW.company_id THEN
        RAISE EXCEPTION 'An allocation, its payment and its document belong to one company' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF pay.status <> 'posted' THEN
        RAISE EXCEPTION 'A reversed payment settles nothing' USING ERRCODE = 'check_violation';
    END IF;
    settles := CASE
        WHEN pay.kind = 'payment' AND pay.direction = 'in'  THEN 'invoice'
        WHEN pay.kind = 'payment' AND pay.direction = 'out' THEN 'bill'
        WHEN pay.kind = 'refund'  AND pay.direction = 'out' THEN 'credit_note'
        ELSE 'vendor_credit' END;
    IF doc.type <> settles OR doc.contact_id IS DISTINCT FROM pay.contact_id OR doc.currency <> pay.currency THEN
        RAISE EXCEPTION 'A payment settles invoices or bills, a refund credit notes or supplier credits, of its own contact and currency'
            USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > payment_unused(NEW.payment_id) THEN
        RAISE EXCEPTION 'A payment cannot settle more than it brought in' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

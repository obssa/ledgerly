-- Evenbooks core schema, part 103: scheduled supplier payments (Module 3).
--
-- A payment planned for a later day: which supplier, which of their bills and how much of each, from which money
-- account, by which method. It is a plan, not money: nothing is in the books until someone confirms, on or after the
-- day, that the money went out. Confirming records an ordinary payment made (with any tax withheld, a fee, a cheque
-- number), through the usual payment rules. Rules:
--   1. A scheduled payment belongs to the company, names one of its suppliers and one of its bank, cash or mobile-money
--      accounts in the payment's currency.
--   2. It is scheduled, then paid once (naming the payment it became: a payment made to the same supplier, from the
--      same account, in the same currency, dated on the day it was closed) or cancelled; both are final (state_transitions).
--      While scheduled its day, account, method, reference and note may change; its supplier and currency never do.
--   3. Its lines are open bills of the same supplier in the same currency, each once, with a positive amount; they
--      change only while it is scheduled. A bill is planned in at most one scheduled payment at a time.
--   4. The reminder on the day goes once (reminded_on is set once). The scheduler learns only which are due, through
--      scheduled_payments_due(): ids only, outside any company.

CREATE TABLE scheduled_payments (
    id           uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid        NOT NULL REFERENCES companies (id),
    contact_id   uuid        NOT NULL REFERENCES contacts (id),
    account_id   uuid        NOT NULL REFERENCES accounts (id),
    pay_on       date        NOT NULL,
    method       text        NOT NULL CHECK (method IN ('cash', 'bank_transfer', 'cheque', 'card', 'mobile_money', 'other')),
    reference    text        CHECK (length(reference) <= 100),
    note         text        CHECK (length(note) <= 300),
    currency     char(3)     NOT NULL,
    status       text        NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'paid', 'cancelled')),
    payment_id   uuid        REFERENCES payments (id),
    closed_on    date,
    reminded_on  date,
    created_by   uuid        NOT NULL REFERENCES users (id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK ((status = 'paid') = (payment_id IS NOT NULL)),
    CHECK ((status = 'scheduled') = (closed_on IS NULL))
);
CREATE INDEX scheduled_payments_due ON scheduled_payments (company_id, pay_on) WHERE status = 'scheduled';
CREATE UNIQUE INDEX scheduled_payments_payment_once ON scheduled_payments (payment_id) WHERE payment_id IS NOT NULL;

CREATE TABLE scheduled_payment_bills (
    id                   uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id           uuid          NOT NULL REFERENCES companies (id),
    scheduled_payment_id uuid          NOT NULL REFERENCES scheduled_payments (id),
    document_id          uuid          NOT NULL REFERENCES documents (id),
    amount               numeric(20,2) NOT NULL CHECK (amount > 0),
    UNIQUE (scheduled_payment_id, document_id)
);
CREATE INDEX scheduled_payment_bills_document ON scheduled_payment_bills (company_id, document_id);

INSERT INTO state_transitions (machine, from_state, to_state, action) VALUES
    ('scheduled_payment', '',          'scheduled', 'SchedulePayment'),
    ('scheduled_payment', 'scheduled', 'paid',      'ConfirmScheduledPayment'),
    ('scheduled_payment', 'scheduled', 'cancelled', 'CancelScheduledPayment');

CREATE TRIGGER scheduled_payments_state_machine BEFORE INSERT OR UPDATE ON scheduled_payments
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('scheduled_payment');

CREATE FUNCTION scheduled_payments_valid() RETURNS trigger LANGUAGE plpgsql AS $$
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
        SELECT 1 FROM accounts WHERE id = NEW.account_id AND company_id = NEW.company_id AND is_active
           AND subtype IN ('bank', 'cash', 'mobile_money') AND coalesce(currency, (SELECT base_currency FROM companies WHERE id = NEW.company_id)) = NEW.currency) THEN
        RAISE EXCEPTION 'A scheduled payment goes out of one of the company''s bank, cash or mobile-money accounts, in its currency'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER scheduled_payments_valid BEFORE INSERT OR UPDATE ON scheduled_payments
    FOR EACH ROW EXECUTE FUNCTION scheduled_payments_valid();

CREATE FUNCTION scheduled_payment_bills_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    plan scheduled_payments;
BEGIN
    SELECT * INTO plan FROM scheduled_payments
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.scheduled_payment_id ELSE NEW.scheduled_payment_id END;
    IF plan.status IS DISTINCT FROM 'scheduled' THEN
        RAISE EXCEPTION 'The bills of a scheduled payment change only while it is scheduled' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.scheduled_payment_id <> OLD.scheduled_payment_id OR NEW.document_id <> OLD.document_id OR NEW.company_id <> OLD.company_id) THEN
        RAISE EXCEPTION 'A line of a scheduled payment changes only its amount' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.company_id <> plan.company_id OR NOT EXISTS (
        SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.company_id = plan.company_id AND d.type = 'bill'
           AND d.contact_id = plan.contact_id AND d.currency = plan.currency AND d.status IN ('posted', 'part_paid')) THEN
        RAISE EXCEPTION 'A scheduled payment settles open bills of its own supplier, in its currency' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND EXISTS (
        SELECT 1 FROM scheduled_payment_bills b JOIN scheduled_payments s ON s.id = b.scheduled_payment_id
         WHERE b.company_id = NEW.company_id AND b.document_id = NEW.document_id AND s.status = 'scheduled' AND s.id <> NEW.scheduled_payment_id) THEN
        RAISE EXCEPTION 'That bill is already in another scheduled payment' USING ERRCODE = 'unique_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER scheduled_payment_bills_valid BEFORE INSERT OR UPDATE OR DELETE ON scheduled_payment_bills
    FOR EACH ROW EXECUTE FUNCTION scheduled_payment_bills_valid();

ALTER TABLE scheduled_payments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON scheduled_payments
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
ALTER TABLE scheduled_payment_bills ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON scheduled_payment_bills
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON scheduled_payments, scheduled_payment_bills FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON scheduled_payments TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON scheduled_payment_bills TO {{app_role}};

-- Scheduled payments whose day came in the last week up to p_on and that were not reminded of yet (a missed day
-- catches up; one older than a week is let go: it still shows as due on the screen). Ids only, outside any company.
CREATE FUNCTION scheduled_payments_due(p_on date)
RETURNS TABLE (company_id uuid, scheduled_payment_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT s.company_id, s.id
      FROM scheduled_payments s
      JOIN companies c ON c.id = s.company_id
     WHERE app_company_id() IS NULL AND app_user_id() IS NULL
       AND c.status <> 'archived'
       AND s.status = 'scheduled' AND s.reminded_on IS NULL
       AND s.pay_on <= p_on AND s.pay_on > p_on - 7
     ORDER BY s.company_id, s.pay_on, s.id
     LIMIT 2000
$$;
REVOKE ALL ON FUNCTION scheduled_payments_due(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION scheduled_payments_due(date) TO {{app_role}};
REVOKE ALL ON FUNCTION scheduled_payments_valid() FROM PUBLIC;
REVOKE ALL ON FUNCTION scheduled_payment_bills_valid() FROM PUBLIC;

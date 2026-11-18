-- Ledgerly core schema, part 62: post-dated cheques (Module 5).
--
-- A cheque dated later than the day it changes hands (a customer pays with next month's cheque, or the company writes
-- one) is held in a register, not in the books: it is not money yet. When its date comes it is presented, and only then
-- becomes a payment (a cheque received into, or paid from, the bank account), through the usual payment rules. Until
-- then it can be cancelled (returned to the drawer, or torn up). Rules:
--   1. A post-dated cheque belongs to the company, names its own customer (in) or supplier (out) and bank account, and
--      is dated after the day it was received or written.
--   2. It is held, then presented once (naming the payment it became: a cheque of the same direction, contact,
--      account, number, currency and amount, dated on or after the cheque's date) or cancelled; nothing else changes.
--   3. A cheque the company writes is numbered once per bank account, among cheques written and post-dated ones held.

CREATE TABLE post_dated_cheques (
    id            uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid          NOT NULL REFERENCES companies (id),
    direction     text          NOT NULL CHECK (direction IN ('in', 'out')),
    contact_id    uuid          NOT NULL REFERENCES contacts (id),
    account_id    uuid          NOT NULL REFERENCES accounts (id),
    cheque_number text          NOT NULL CHECK (cheque_number ~ '^[0-9A-Za-z-]{1,20}$'),
    drawn_on      text          CHECK (length(drawn_on) <= 80),   -- the payer's bank, for a cheque received
    cheque_date   date          NOT NULL,
    handed_on     date          NOT NULL,                          -- received from the customer, or given to the supplier
    currency      char(3)       NOT NULL,
    amount        numeric(20,2) NOT NULL CHECK (amount > 0),
    note          text          CHECK (length(note) <= 300),
    status        text          NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'presented', 'cancelled')),
    payment_id    uuid,
    closed_on     date,
    created_by    uuid          NOT NULL REFERENCES users (id),
    created_at    timestamptz   NOT NULL DEFAULT now(),
    updated_at    timestamptz   NOT NULL DEFAULT now(),
    CHECK (cheque_date > handed_on),
    CHECK ((status = 'presented') = (payment_id IS NOT NULL)),
    CHECK ((status = 'held') = (closed_on IS NULL))
);
CREATE INDEX post_dated_cheques_due ON post_dated_cheques (company_id, cheque_date) WHERE status = 'held';
CREATE UNIQUE INDEX post_dated_cheques_number_once ON post_dated_cheques (company_id, account_id, cheque_number)
    WHERE direction = 'out' AND status <> 'cancelled';

CREATE FUNCTION post_dated_cheques_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'held' THEN
            RAISE EXCEPTION 'A post-dated cheque is recorded as held' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id
                          AND CASE WHEN NEW.direction = 'in' THEN is_customer ELSE is_vendor END) THEN
            RAISE EXCEPTION 'A cheque received is from one of the company''s customers, a cheque written to one of its suppliers'
                USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.account_id AND company_id = NEW.company_id AND subtype = 'bank'
                          AND (currency IS NULL OR currency = NEW.currency)) THEN
            RAISE EXCEPTION 'A cheque goes through one of the company''s bank accounts, in its currency' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.direction = 'out' AND (
               EXISTS (SELECT 1 FROM payments WHERE company_id = NEW.company_id AND account_id = NEW.account_id AND direction = 'out'
                                                AND cheque_number = NEW.cheque_number)
            OR EXISTS (SELECT 1 FROM post_dated_cheques WHERE company_id = NEW.company_id AND account_id = NEW.account_id AND direction = 'out'
                                                         AND cheque_number = NEW.cheque_number AND status <> 'cancelled')) THEN
            RAISE EXCEPTION 'Cheque % of this account is already written', NEW.cheque_number USING ERRCODE = 'unique_violation';
        END IF;
        RETURN NEW;
    END IF;

    IF (to_jsonb(NEW) - 'status' - 'payment_id' - 'closed_on' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'payment_id' - 'closed_on' - 'updated_at') THEN
        RAISE EXCEPTION 'A post-dated cheque stays as recorded; cancel it and record it again' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status AND OLD.status <> 'held' THEN
        RAISE EXCEPTION 'Cheque % was % already', OLD.cheque_number, OLD.status USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'presented' AND OLD.status = 'held' AND NOT EXISTS (
        SELECT 1 FROM payments p
         WHERE p.id = NEW.payment_id AND p.company_id = NEW.company_id AND p.direction = NEW.direction AND p.contact_id = NEW.contact_id
           AND p.account_id = NEW.account_id AND p.method = 'cheque' AND p.cheque_number = NEW.cheque_number AND p.currency = NEW.currency
           AND p.amount = NEW.amount AND p.payment_date >= NEW.cheque_date AND p.payment_date = NEW.closed_on) THEN
        RAISE EXCEPTION 'A presented cheque names the payment it became, on or after its date' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'cancelled' AND OLD.status = 'held' AND NEW.closed_on < NEW.handed_on THEN
        RAISE EXCEPTION 'A cheque is cancelled no earlier than it was handed over' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER post_dated_cheques_valid BEFORE INSERT OR UPDATE ON post_dated_cheques
    FOR EACH ROW EXECUTE FUNCTION post_dated_cheques_valid();

ALTER TABLE post_dated_cheques ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON post_dated_cheques
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON post_dated_cheques FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON post_dated_cheques TO {{app_role}};

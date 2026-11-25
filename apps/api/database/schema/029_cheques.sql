-- Evenbooks core schema, part 29: cheques (Module 5).
--
-- A cheque is a payment by the method 'cheque'. Rules here:
--   1. Only a cheque has a cheque number. A cheque the company writes has one, and it is used once per bank account:
--      a cancelled cheque keeps its number, as it does in the cheque book.
--   2. Only a cheque received can bounce, and it bounces no earlier than it was received; a bounced cheque is a reversed
--      payment (the reversal is the posting rule: Dr accounts receivable, Cr bank).
-- Rows written before these rules (NOT VALID) are left as they are; every new or changed row is checked.

ALTER TABLE payments
    ADD COLUMN cheque_number text CHECK (cheque_number ~ '^[0-9A-Za-z-]{1,20}$'),
    ADD COLUMN bounced_on date;

ALTER TABLE payments
    ADD CONSTRAINT payments_cheque_number_only_on_cheques CHECK (cheque_number IS NULL OR method = 'cheque') NOT VALID,
    ADD CONSTRAINT payments_cheques_written_are_numbered CHECK (NOT (direction = 'out' AND method = 'cheque') OR cheque_number IS NOT NULL) NOT VALID,
    ADD CONSTRAINT payments_bounce_valid CHECK (bounced_on IS NULL
        OR (direction = 'in' AND method = 'cheque' AND status = 'reversed' AND bounced_on >= payment_date)) NOT VALID;

-- 016 keeps a recorded payment as it was; the day a cheque bounced is written in the same step that reverses it, once.
CREATE OR REPLACE FUNCTION payments_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Payments are reversed, never deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF (to_jsonb(NEW) - 'status' - 'journal_entry_id' - 'bounced_on') <> (to_jsonb(OLD) - 'status' - 'journal_entry_id' - 'bounced_on')
       OR (OLD.journal_entry_id IS NOT NULL AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
       OR (NEW.bounced_on IS DISTINCT FROM OLD.bounced_on AND NOT (OLD.bounced_on IS NULL AND OLD.status = 'posted' AND NEW.status = 'reversed')) THEN
        RAISE EXCEPTION 'A recorded payment does not change; reverse it and record it again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE UNIQUE INDEX payments_cheque_number_once ON payments (company_id, account_id, cheque_number)
    WHERE direction = 'out' AND cheque_number IS NOT NULL;

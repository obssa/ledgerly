-- Ledgerly core schema, part 16: payments received and their allocations (Module 2).
--
-- Builds on 006: inserting an allocation lowers the invoice's amount due (and its status follows), deleting one
-- (when a payment is reversed) puts it back. This part adds:
--   1. A payment is recorded once and only ever reversed (a bounced cheque, a cancelled transfer): its amounts never
--      change and it is never deleted. Only its status moves, and its journal entry is set once.
--   2. An allocation settles an invoice of the same company, the same customer and the same currency, never for a
--      reversed payment, and a payment never settles more than it brought in (amount received + tax withheld).
--   3. An allocation is never edited: 006 adjusts the invoice only on insert and delete.

CREATE FUNCTION payments_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Payments are reversed, never deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF (to_jsonb(NEW) - 'status' - 'journal_entry_id') <> (to_jsonb(OLD) - 'status' - 'journal_entry_id')
       OR (OLD.journal_entry_id IS NOT NULL AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id) THEN
        RAISE EXCEPTION 'A recorded payment does not change; reverse it and record it again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payments_frozen BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION payments_frozen();

-- SECURITY DEFINER: the sum of a payment's allocations must count every row, whatever the caller can see.
CREATE FUNCTION payment_allocations_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    pay record;
    doc record;
BEGIN
    SELECT company_id, contact_id, currency, amount, withholding, status INTO pay FROM payments WHERE id = NEW.payment_id FOR UPDATE;
    SELECT company_id, contact_id, currency, type INTO doc FROM documents WHERE id = NEW.document_id;
    IF pay.company_id IS DISTINCT FROM NEW.company_id OR doc.company_id IS DISTINCT FROM NEW.company_id THEN
        RAISE EXCEPTION 'An allocation, its payment and its invoice belong to one company' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF pay.status <> 'posted' THEN
        RAISE EXCEPTION 'A reversed payment settles nothing' USING ERRCODE = 'check_violation';
    END IF;
    IF doc.type <> 'invoice' OR doc.contact_id IS DISTINCT FROM pay.contact_id OR doc.currency <> pay.currency THEN
        RAISE EXCEPTION 'A payment settles invoices of its own customer, in its own currency' USING ERRCODE = 'check_violation';
    END IF;
    IF (SELECT coalesce(sum(amount), 0) FROM payment_allocations WHERE payment_id = NEW.payment_id) + NEW.amount
       > pay.amount + pay.withholding THEN
        RAISE EXCEPTION 'A payment cannot settle more than it brought in' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION payment_allocations_valid() FROM PUBLIC;
CREATE TRIGGER payment_allocations_valid BEFORE INSERT ON payment_allocations FOR EACH ROW EXECUTE FUNCTION payment_allocations_valid();

REVOKE UPDATE ON payment_allocations FROM {{app_role}};
REVOKE DELETE ON payments FROM {{app_role}};

-- Ledgerly core schema, part 78: bad debts written off (Module 2).
--
-- A customer who will not pay: what an invoice still owes, or part of it, is written off. One entry moves it out of
-- accounts receivable into bad debts (Dr Bad debts, Cr Accounts receivable, at the invoice's own rate, so nothing is
-- left in exchange differences), and the invoice owes that much less. It is not a credit note: nothing is sent to the
-- customer or to the tax authority, and any VAT relief on a bad debt is for the company's tax adviser. If the customer
-- pays after all, the write-off is reversed and the invoice is owed again. Rules:
--   1. A write-off is of a posted invoice of the company, never more than it still owes, on or after its date.
--   2. Its entries are the company's own bad-debt entries.
--   3. A write-off stays as made: it is only reversed, once, on or after its date; never removed.
--   4. An invoice with a write-off standing is neither voided nor edited until the write-off is reversed.

CREATE TABLE invoice_write_offs (
    id                uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    document_id       uuid          NOT NULL REFERENCES documents (id),
    amount            numeric(20,2) NOT NULL CHECK (amount > 0),
    written_off_on    date          NOT NULL,
    reason            text          NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 500),
    entry_id          uuid          NOT NULL,
    written_off_by    uuid          NOT NULL REFERENCES users (id),
    reversed_on       date,
    reversal_entry_id uuid,
    reversed_by       uuid          REFERENCES users (id),
    reversal_reason   text          CHECK (length(reversal_reason) <= 500),
    created_at        timestamptz   NOT NULL DEFAULT now(),
    CHECK ((reversed_on IS NULL) = (reversal_entry_id IS NULL) AND (reversed_on IS NULL) = (reversed_by IS NULL)),
    CHECK (reversed_on IS NULL OR reversed_on >= written_off_on)
);
CREATE INDEX invoice_write_offs_document ON invoice_write_offs (company_id, document_id);

CREATE FUNCTION invoice_write_off_entry_ok(p_company uuid, p_entry uuid, p_source uuid, p_type text) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM journal_entries
                    WHERE company_id = p_company AND id = p_entry AND source_type = p_type AND source_id = p_source)
$$;
REVOKE ALL ON FUNCTION invoice_write_off_entry_ok(uuid, uuid, uuid, text) FROM PUBLIC;

CREATE FUNCTION invoice_write_offs_apply() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    doc record;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A write-off is kept: reverse it instead' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'INSERT' THEN
        SELECT type, status, amount_due, number, issue_date INTO doc
          FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id FOR UPDATE;
        IF NOT FOUND OR doc.type <> 'invoice' OR doc.status NOT IN ('posted', 'part_paid') THEN
            RAISE EXCEPTION 'A bad debt is written off a posted invoice of the company that still owes something' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.amount > doc.amount_due THEN
            RAISE EXCEPTION 'Writing off % is more than the % invoice % still owes', NEW.amount, doc.amount_due, doc.number USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.written_off_on < doc.issue_date OR NEW.reversed_on IS NOT NULL THEN
            RAISE EXCEPTION 'A write-off is dated on or after its invoice, and reversed later' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT invoice_write_off_entry_ok(NEW.company_id, NEW.entry_id, NEW.id, 'bad_debt') THEN
            RAISE EXCEPTION 'A write-off is made with its own bad-debt entry' USING ERRCODE = 'check_violation';
        END IF;
        UPDATE documents SET amount_due = amount_due - NEW.amount, updated_at = now() WHERE id = NEW.document_id;
        RETURN NEW;
    END IF;

    -- UPDATE: only the reversal, once.
    IF (NEW.id, NEW.company_id, NEW.document_id, NEW.amount, NEW.written_off_on, NEW.reason, NEW.entry_id, NEW.written_off_by)
       IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.document_id, OLD.amount, OLD.written_off_on, OLD.reason, OLD.entry_id, OLD.written_off_by)
       OR OLD.reversed_on IS NOT NULL OR NEW.reversed_on IS NULL THEN
        RAISE EXCEPTION 'A write-off stays as made: it is only reversed, once' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT invoice_write_off_entry_ok(NEW.company_id, NEW.reversal_entry_id, OLD.entry_id, 'reversal') THEN
        RAISE EXCEPTION 'A write-off is reversed by the reversal of its own entry' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM documents WHERE id = NEW.document_id FOR UPDATE;
    UPDATE documents SET amount_due = amount_due + NEW.amount, updated_at = now() WHERE id = NEW.document_id;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION invoice_write_offs_apply() FROM PUBLIC;
CREATE TRIGGER invoice_write_offs_apply BEFORE INSERT OR UPDATE OR DELETE ON invoice_write_offs
    FOR EACH ROW EXECUTE FUNCTION invoice_write_offs_apply();

-- Rule 4.
CREATE FUNCTION documents_write_off_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF (NEW.status = 'void' AND OLD.status <> 'void' OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
       AND EXISTS (SELECT 1 FROM invoice_write_offs WHERE document_id = OLD.id AND reversed_on IS NULL) THEN
        RAISE EXCEPTION 'Invoice % has a bad debt written off: reverse the write-off before voiding or editing the invoice', OLD.number USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION documents_write_off_guard() FROM PUBLIC;
CREATE TRIGGER documents_write_off_guard BEFORE UPDATE OF status, journal_entry_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_write_off_guard();

ALTER TABLE invoice_write_offs ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invoice_write_offs
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invoice_write_offs FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON invoice_write_offs TO {{app_role}};

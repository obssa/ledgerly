-- Evenbooks core schema, part 50: retention held and released (Module 9a, construction).
--
-- A customer may hold back part of an invoice (retention) until the work is complete or a defects period ends. Holding
-- it moves that part out of what is owed now: the invoice's open amount goes down and the ledger moves it from accounts
-- receivable to retention receivable (one entry, posted by the posting service). Releasing it does the reverse, and the
-- invoice is owed again. Rules here:
--   1. Retention is held on a posted invoice of the company, never more than it still owes.
--   2. Its entries are the company's own retention entries.
--   3. A retention record stays as held: it is only released, once, on or after the day it was held; never removed.
--   4. An invoice holding retention is neither voided nor edited until the retention is released.

CREATE TABLE invoice_retentions (
    id                uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    document_id       uuid          NOT NULL REFERENCES documents (id),
    amount            numeric(20,2) NOT NULL CHECK (amount > 0),
    held_on           date          NOT NULL,
    held_entry_id     uuid          NOT NULL,
    held_by           uuid          NOT NULL REFERENCES users (id),
    released_on       date,
    released_entry_id uuid,
    released_by       uuid          REFERENCES users (id),
    created_at        timestamptz   NOT NULL DEFAULT now(),
    CHECK ((released_on IS NULL) = (released_entry_id IS NULL) AND (released_on IS NULL) = (released_by IS NULL)),
    CHECK (released_on IS NULL OR released_on >= held_on)
);
CREATE INDEX invoice_retentions_document ON invoice_retentions (company_id, document_id);
CREATE INDEX invoice_retentions_held ON invoice_retentions (company_id) WHERE released_on IS NULL;

CREATE FUNCTION invoice_retention_entry_ok(p_company uuid, p_entry uuid, p_retention uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM journal_entries
                    WHERE company_id = p_company AND id = p_entry AND source_type = 'retention' AND source_id = p_retention)
$$;

CREATE FUNCTION invoice_retentions_apply() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    doc record;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retention is kept as held: release it instead' USING ERRCODE = 'check_violation';
    END IF;

    IF TG_OP = 'INSERT' THEN
        SELECT type, status, amount_due, number, issue_date INTO doc
          FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id FOR UPDATE;
        IF NOT FOUND OR doc.type <> 'invoice' OR doc.status NOT IN ('posted', 'part_paid') THEN
            RAISE EXCEPTION 'Retention is held on a posted invoice of the company that still owes something' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.amount > doc.amount_due THEN
            RAISE EXCEPTION 'Retention % is more than the % invoice % still owes', NEW.amount, doc.amount_due, doc.number USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.held_on < doc.issue_date OR NEW.released_on IS NOT NULL THEN
            RAISE EXCEPTION 'Retention is held on or after the invoice date, and released later' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT invoice_retention_entry_ok(NEW.company_id, NEW.held_entry_id, NEW.id) THEN
            RAISE EXCEPTION 'Retention is held with its own retention entry' USING ERRCODE = 'check_violation';
        END IF;
        UPDATE documents SET amount_due = amount_due - NEW.amount, updated_at = now() WHERE id = NEW.document_id;
        RETURN NEW;
    END IF;

    -- UPDATE: only the release, once.
    IF (NEW.id, NEW.company_id, NEW.document_id, NEW.amount, NEW.held_on, NEW.held_entry_id, NEW.held_by)
       IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.document_id, OLD.amount, OLD.held_on, OLD.held_entry_id, OLD.held_by)
       OR OLD.released_on IS NOT NULL OR NEW.released_on IS NULL THEN
        RAISE EXCEPTION 'Retention stays as held: it is only released, once' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT invoice_retention_entry_ok(NEW.company_id, NEW.released_entry_id, NEW.id) THEN
        RAISE EXCEPTION 'Retention is released with its own retention entry' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM documents WHERE id = NEW.document_id FOR UPDATE;
    UPDATE documents SET amount_due = amount_due + NEW.amount, updated_at = now() WHERE id = NEW.document_id;
    RETURN NEW;
END $$;
CREATE TRIGGER invoice_retentions_apply BEFORE INSERT OR UPDATE OR DELETE ON invoice_retentions
    FOR EACH ROW EXECUTE FUNCTION invoice_retentions_apply();

-- An invoice holding retention is neither voided nor re-posted by an edit until the retention is released.
CREATE FUNCTION documents_retention_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF (NEW.status = 'void' AND OLD.status <> 'void' OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
       AND EXISTS (SELECT 1 FROM invoice_retentions WHERE document_id = OLD.id AND released_on IS NULL) THEN
        RAISE EXCEPTION 'Invoice % holds retention: release it before voiding or editing the invoice', OLD.number USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_retention_guard BEFORE UPDATE OF status, journal_entry_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_retention_guard();

ALTER TABLE invoice_retentions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invoice_retentions
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invoice_retentions FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON invoice_retentions TO {{app_role}};
REVOKE ALL ON FUNCTION invoice_retention_entry_ok(uuid, uuid, uuid) FROM PUBLIC;

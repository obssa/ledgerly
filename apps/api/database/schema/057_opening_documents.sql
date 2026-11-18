-- Ledgerly core schema, part 57: open invoices and bills brought in at conversion (Module 1).
--
-- What customers still owed and what the company still owed suppliers on the conversion date come in as the
-- invoices and bills behind them, so receivables and payables always equal their detail: each one keeps its own
-- number and dates (for ageing, statements and payments), owes its open amount, and posts on the conversion date
-- against opening balance equity, never to income, cost or tax (that was in the old books). It is never sent to the
-- tax authority again.

ALTER TABLE documents ADD COLUMN opening boolean NOT NULL DEFAULT false;
ALTER TABLE documents ADD CONSTRAINT documents_opening_kinds CHECK (NOT opening OR type IN ('invoice', 'bill'));

CREATE FUNCTION documents_opening_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    conversion date;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.opening IS DISTINCT FROM OLD.opening THEN
        RAISE EXCEPTION 'Whether a document came from the old books never changes' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.opening THEN
        SELECT conversion_date INTO conversion FROM companies WHERE id = NEW.company_id;
        IF conversion IS NULL OR NEW.issue_date > conversion THEN
            RAISE EXCEPTION 'An invoice or bill from the old books is dated on or before the conversion date' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_opening_valid BEFORE INSERT OR UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_opening_valid();

-- An invoice from the old books was registered (or not) there: it is not submitted again.
CREATE FUNCTION einvoice_submissions_not_opening() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id AND opening) THEN
        RAISE EXCEPTION 'An invoice from the old books is not sent to the tax authority again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER einvoice_submissions_not_opening BEFORE INSERT ON einvoice_submissions
    FOR EACH ROW EXECUTE FUNCTION einvoice_submissions_not_opening();

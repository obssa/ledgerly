-- Ledgerly core schema, part 13: document numbering (Module 1).
--
-- With automatic numbering a draft has no number: the number is taken when the document is posted, from a
-- per-company counter (company_sequences) locked for that instant, in the same transaction as the posting. A
-- posting that fails takes no number, and a posted document is voided, never deleted, so numbers have no gaps.
-- Manual numbering is for companies that issue from pre-printed, authority-approved books: the number is typed
-- on the draft and must be unique.
--
-- Whatever the mode, a posted document has a number and that number never changes.

ALTER TABLE documents ALTER COLUMN number DROP NOT NULL;
ALTER TABLE documents ADD CONSTRAINT documents_posted_have_a_number
    CHECK (number IS NOT NULL OR status IN ('draft', 'awaiting_approval', 'void', 'sent', 'accepted', 'declined', 'expired'));
ALTER TABLE documents ADD CONSTRAINT documents_number_length CHECK (length(number) BETWEEN 1 AND 40);

CREATE FUNCTION documents_keep_posted_number() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.number IS NOT NULL AND NEW.number IS DISTINCT FROM OLD.number
       AND OLD.status NOT IN ('draft', 'awaiting_approval') THEN
        RAISE EXCEPTION 'Document % is posted; its number cannot change', OLD.number USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_keep_posted_number BEFORE UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_keep_posted_number();

-- How each type of document is numbered. No row means the defaults in the API (automatic, the type's prefix,
-- with the fiscal year, four digits).
CREATE TABLE document_numbering (
    company_id   uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    doc_type     text        NOT NULL CHECK (doc_type IN ('invoice', 'credit_note', 'bill', 'vendor_credit', 'sales_receipt',
                                                          'quote', 'sales_order', 'purchase_order')),
    mode         text        NOT NULL DEFAULT 'automatic' CHECK (mode IN ('automatic', 'manual')),
    prefix       text        NOT NULL DEFAULT '' CHECK (prefix ~ '^[A-Za-z0-9/._-]{0,12}$'),
    include_year boolean     NOT NULL DEFAULT true,   -- the fiscal year in the number; the counter restarts each year
    padding      smallint    NOT NULL DEFAULT 4 CHECK (padding BETWEEN 1 AND 8),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, doc_type)
);

ALTER TABLE document_numbering ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON document_numbering
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
GRANT SELECT, INSERT, UPDATE ON document_numbering TO {{app_role}};

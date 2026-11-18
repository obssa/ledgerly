-- Ledgerly core schema, part 63: files attached to documents (SECURITY.md S7; Module 3 receipts).
--
-- A receipt, a supplier's invoice or a contract kept with the bill, expense or invoice it backs. The file itself is in
-- private storage outside the web root under a random name; this table holds what is known about it. Rules:
--   1. An attachment belongs to one of the company's own documents, and a document keeps at most 20 at a time.
--   2. Only a PDF, PNG, JPEG or WebP file of 1 byte to 10 MB is kept, under a storage key no other file has.
--   3. Attachments are never deleted or changed: one taken off is marked removed, with who and when (the file stays).

CREATE TABLE attachments (
    id           uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id   uuid        NOT NULL REFERENCES companies (id),
    document_id  uuid        NOT NULL REFERENCES documents (id),
    file_name    text        NOT NULL CHECK (length(file_name) BETWEEN 1 AND 160),
    content_type text        NOT NULL CHECK (content_type IN ('application/pdf', 'image/png', 'image/jpeg', 'image/webp')),
    size_bytes   integer     NOT NULL CHECK (size_bytes BETWEEN 1 AND 10485760),
    sha256       text        NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    storage_key  text        NOT NULL UNIQUE CHECK (storage_key ~ '^[0-9a-f]{40}$'),
    created_by   uuid        NOT NULL REFERENCES users (id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    removed_at   timestamptz,
    removed_by   uuid        REFERENCES users (id),
    CHECK ((removed_at IS NULL) = (removed_by IS NULL))
);
CREATE INDEX attachments_document ON attachments (company_id, document_id) WHERE removed_at IS NULL;

CREATE FUNCTION attachments_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.removed_at IS NOT NULL THEN
            RAISE EXCEPTION 'An attachment is added, then removed' USING ERRCODE = 'check_violation';
        END IF;
        PERFORM 1 FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'An attachment belongs to one of the company''s own documents' USING ERRCODE = 'check_violation';
        END IF;
        IF (SELECT count(*) FROM attachments WHERE company_id = NEW.company_id AND document_id = NEW.document_id AND removed_at IS NULL) >= 20 THEN
            RAISE EXCEPTION 'A document keeps at most 20 attachments' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF (to_jsonb(NEW) - 'removed_at' - 'removed_by') <> (to_jsonb(OLD) - 'removed_at' - 'removed_by') OR OLD.removed_at IS NOT NULL THEN
        RAISE EXCEPTION 'An attachment stays as it was added; it can only be removed, once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER attachments_valid BEFORE INSERT OR UPDATE ON attachments
    FOR EACH ROW EXECUTE FUNCTION attachments_valid();

ALTER TABLE attachments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON attachments
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON attachments FROM {{app_role}};
GRANT SELECT, INSERT ON attachments TO {{app_role}};
GRANT UPDATE (removed_at, removed_by) ON attachments TO {{app_role}};

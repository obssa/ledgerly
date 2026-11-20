-- Ledgerly core schema, part 94: custom tags (Module 1).
--
-- A tag is a label of the company's own ("Trade fair 2027", "Addis branch launch") put on whole transactions: an
-- invoice, bill or expense, or a journal entry. Unlike the location, class and project dimensions it sits on the
-- transaction, not on each line, and a transaction can have several. The profit by tag report reads the ledger: the
-- entries of a tagged document or tagged themselves, and their reversals. Tagging changes nothing in the ledger.
-- Rules:
--   1. A tag's name is the company's once, whatever its case; a tag is never deleted, only turned off.
--   2. A tagging names one of the company's documents or journal entries (not both) and one of its tags, active
--      when it is put on; the same tag goes on a transaction once.

CREATE TABLE tags (
    id          uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id),
    name        text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 40),
    is_active   boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX tags_name_once ON tags (company_id, lower(btrim(name)));

CREATE TABLE transaction_tags (
    id                uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid        NOT NULL REFERENCES companies (id),
    tag_id            uuid        NOT NULL REFERENCES tags (id),
    document_id       uuid        REFERENCES documents (id),
    journal_entry_id  uuid,
    created_by        uuid        NOT NULL REFERENCES users (id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    CHECK ((document_id IS NULL) <> (journal_entry_id IS NULL)),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
);
CREATE UNIQUE INDEX transaction_tags_document_once ON transaction_tags (tag_id, document_id) WHERE document_id IS NOT NULL;
CREATE UNIQUE INDEX transaction_tags_entry_once ON transaction_tags (tag_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL;
CREATE INDEX transaction_tags_company ON transaction_tags (company_id, tag_id);

CREATE FUNCTION tags_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A tag is turned off, never deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.company_id <> OLD.company_id THEN
        RAISE EXCEPTION 'A tag stays its company''s' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION tags_valid() FROM PUBLIC;
CREATE TRIGGER tags_valid BEFORE UPDATE OR DELETE ON tags FOR EACH ROW EXECUTE FUNCTION tags_valid();

CREATE FUNCTION transaction_tags_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'A tagging is taken off and put on again, never changed' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tags WHERE id = NEW.tag_id AND company_id = NEW.company_id AND is_active) THEN
        RAISE EXCEPTION 'A transaction is tagged with an active tag of its company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.document_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A tagged document is one of the company''s' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION transaction_tags_valid() FROM PUBLIC;
CREATE TRIGGER transaction_tags_valid BEFORE INSERT OR UPDATE ON transaction_tags FOR EACH ROW EXECUTE FUNCTION transaction_tags_valid();

ALTER TABLE tags ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tags
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
ALTER TABLE transaction_tags ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON transaction_tags
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON tags, transaction_tags FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON tags TO {{app_role}};
GRANT SELECT, INSERT, DELETE ON transaction_tags TO {{app_role}};

-- Evenbooks core schema, part 5: permanent edit history. (Applied before 006_rls.sql.)
--
--   1. document_versions: a full snapshot of a posted document is saved before every edit. Append-only.
--   2. A posted document (and its lines) can only change after its current version is in document_versions:
--      the database refuses an edit that would skip the history.
--   3. audit_log becomes tamper-evident: every row stores the hash of the company's previous row
--      (a hash chain). audit_log_verify() reports the first row where the chain breaks.

-- 1. Document versions ----------------------------------------------------------------------------------

CREATE TABLE document_versions (
    id               uuid        NOT NULL DEFAULT gen_random_uuid(),
    company_id       uuid        NOT NULL REFERENCES companies (id),
    document_id      uuid        NOT NULL REFERENCES documents (id),
    version          integer     NOT NULL CHECK (version >= 1),
    journal_entry_id uuid,                                        -- the entry this version was posted with
    snapshot         jsonb       NOT NULL,                        -- the document and its lines exactly as they were
    reason           text        NOT NULL CHECK (length(trim(reason)) >= 3),
    edited_by        uuid        REFERENCES users (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    UNIQUE (company_id, document_id, version),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
) PARTITION BY HASH (company_id);
SELECT create_hash_partitions('document_versions', 16);
CREATE INDEX document_versions_entry_idx ON document_versions (company_id, document_id, journal_entry_id);

CREATE FUNCTION history_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION '% is append-only: history cannot be changed or deleted', TG_TABLE_NAME
        USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER document_versions_no_changes
    BEFORE UPDATE OR DELETE ON document_versions
    FOR EACH ROW EXECUTE FUNCTION history_append_only();


-- 2. Posted documents change only after their current version is saved ----------------------------------

CREATE FUNCTION document_version_saved(p_company_id uuid, p_document_id uuid, p_entry_id uuid) RETURNS boolean
LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM document_versions
         WHERE company_id = p_company_id AND document_id = p_document_id
           AND journal_entry_id IS NOT DISTINCT FROM p_entry_id
    )
$$;

-- Posted documents are frozen. Payments and voiding move only status, amount_due, lock_version and
-- updated_at. Anything else is an edit, allowed only for an unpaid posted document whose current version
-- is saved and which is re-posted with a new journal entry (the old one having been reversed).
CREATE FUNCTION documents_guard_posted() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    moving constant text[] := ARRAY['status', 'amount_due', 'lock_version', 'updated_at'];
BEGIN
    IF OLD.status NOT IN ('posted', 'part_paid', 'paid', 'void') THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Posted documents cannot be deleted; void them instead' USING ERRCODE = 'insufficient_privilege';
    END IF;

    IF (to_jsonb(NEW) - moving) = (to_jsonb(OLD) - moving) THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'posted'
       AND document_version_saved(OLD.company_id, OLD.id, OLD.journal_entry_id)
       AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'Posted document % can only be edited while unpaid and after its current version is saved to history', OLD.number
        USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER documents_frozen_when_posted
    BEFORE UPDATE OR DELETE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_guard_posted();

CREATE FUNCTION document_lines_guard_posted() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    doc record;
BEGIN
    SELECT company_id, id, status, journal_entry_id, number INTO doc
      FROM documents WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.document_id ELSE OLD.document_id END;

    IF doc.status IN ('posted', 'part_paid', 'paid', 'void')
       AND NOT document_version_saved(doc.company_id, doc.id, doc.journal_entry_id) THEN
        RAISE EXCEPTION 'Lines of posted document % can only change after its current version is saved to history', doc.number
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

CREATE TRIGGER document_lines_frozen_when_posted
    BEFORE INSERT OR UPDATE OR DELETE ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_guard_posted();

-- 3. Tamper-evident audit log ----------------------------------------------------------------------------

ALTER TABLE audit_log
    ADD COLUMN reason    text,
    ADD COLUMN device    text,
    ADD COLUMN prev_hash text,
    ADD COLUMN row_hash  text;

CREATE FUNCTION audit_log_row_digest(r audit_log) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(sha256(convert_to(coalesce(r.prev_hash, '') || jsonb_build_object(
        'id', r.id, 'company_id', r.company_id, 'user_id', r.user_id, 'actor_type', r.actor_type,
        'action', r.action, 'subject_type', r.subject_type, 'subject_id', r.subject_id,
        'before', r.before, 'after', r.after, 'reason', r.reason, 'ip', r.ip, 'device', r.device,
        'created_at', to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US')
    )::text, 'UTF8')), 'hex')
$$;

-- Runs as the table owner so it can read the previous row whatever RLS context the caller has.
CREATE FUNCTION audit_log_chain() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}} AS $$
BEGIN
    -- One writer at a time per company, so ids and hashes follow the same order.
    PERFORM pg_advisory_xact_lock(hashtext('audit_log:' || NEW.company_id::text));
    NEW.id := nextval(pg_get_serial_sequence('audit_log', 'id'));
    NEW.created_at := clock_timestamp();
    SELECT row_hash INTO NEW.prev_hash
      FROM audit_log WHERE company_id = NEW.company_id ORDER BY id DESC LIMIT 1;
    NEW.row_hash := audit_log_row_digest(NEW);
    RETURN NEW;
END $$;

CREATE TRIGGER audit_log_hash_chain
    BEFORE INSERT ON audit_log
    FOR EACH ROW EXECUTE FUNCTION audit_log_chain();

-- Returns the id of the first row whose hash or link is wrong, or NULL when the whole chain is intact.
CREATE FUNCTION audit_log_verify(p_company_id uuid) RETURNS bigint
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = {{schema}} AS $$
DECLARE
    r        audit_log;
    expected text := NULL;
BEGIN
    FOR r IN SELECT * FROM audit_log WHERE company_id = p_company_id ORDER BY id LOOP  -- one partition
        IF r.prev_hash IS DISTINCT FROM expected OR r.row_hash IS DISTINCT FROM audit_log_row_digest(r) THEN
            RETURN r.id;
        END IF;
        expected := r.row_hash;
    END LOOP;
    RETURN NULL;
END $$;

-- Evenbooks core schema, part 86: attachments on journal entries (Module 6).
--
-- A journal entry may carry the files behind it, as invoices, bills and expenses do (063): the board minute behind an
-- accrual, the loan schedule behind an interest entry, the bank's letter behind a correction. Rules (063's, widened):
--   1. An attachment belongs to exactly one thing of the company: a document or a journal entry.
--   2. One thing keeps at most 20 attachments that are not removed; adding one waits for any other being added to it.
--   3. An attachment stays as it was added; it can only be removed, once.

ALTER TABLE attachments ALTER COLUMN document_id DROP NOT NULL,
    ADD COLUMN journal_entry_id uuid,
    -- Journal entries are kept per company (002): the company's own entry, by key.
    ADD CONSTRAINT attachments_journal_entry_fk FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id),
    ADD CONSTRAINT attachments_one_owner CHECK (num_nonnulls(document_id, journal_entry_id) = 1);
CREATE INDEX attachments_journal_entry ON attachments (company_id, journal_entry_id) WHERE removed_at IS NULL AND journal_entry_id IS NOT NULL;

CREATE OR REPLACE FUNCTION attachments_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.removed_at IS NOT NULL THEN
            RAISE EXCEPTION 'An attachment is added, then removed' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.document_id IS NOT NULL THEN
            PERFORM 1 FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id FOR UPDATE;
            IF NOT FOUND THEN
                RAISE EXCEPTION 'An attachment belongs to one of the company''s own documents' USING ERRCODE = 'check_violation';
            END IF;
        ELSE
            -- Journal entries are append-only (the application cannot lock their rows): wait on the entry's id instead.
            PERFORM pg_advisory_xact_lock(hashtextextended('attachments:' || NEW.journal_entry_id::text, 0));
            IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND company_id = NEW.company_id) THEN
                RAISE EXCEPTION 'An attachment belongs to one of the company''s own journal entries' USING ERRCODE = 'check_violation';
            END IF;
        END IF;
        IF (SELECT count(*) FROM attachments WHERE company_id = NEW.company_id AND removed_at IS NULL
              AND (document_id = NEW.document_id OR journal_entry_id = NEW.journal_entry_id)) >= 20 THEN
            RAISE EXCEPTION 'A document keeps at most 20 attachments' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF (to_jsonb(NEW) - 'removed_at' - 'removed_by') <> (to_jsonb(OLD) - 'removed_at' - 'removed_by') OR OLD.removed_at IS NOT NULL THEN
        RAISE EXCEPTION 'An attachment stays as it was added; it can only be removed, once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- Evenbooks core schema, part 33: manual journals waiting for approval (Module 6).
--
-- The one optional approval step for manual journals: when the company sets settings.approval.journal_above, a manual
-- journal above it is not posted but kept as a request. Rules here:
--   1. A request is made by the person signed in, and decided by the person signed in.
--   2. It is decided once: approved (it names the manual journal it posted), rejected (with a note), or withdrawn by the
--      person who asked. Someone other than the person who asked approves or rejects it.
--   3. What was asked (date, memo, lines, amount, who) never changes; requests are never deleted.

CREATE TABLE journal_requests (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    entry_date       date          NOT NULL,
    memo             text          NOT NULL CHECK (length(memo) BETWEEN 3 AND 500),
    lines            jsonb         NOT NULL CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 2 AND 500),
    total            numeric(20,2) NOT NULL CHECK (total > 0),
    status           text          NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn')),
    requested_by     uuid          NOT NULL REFERENCES users (id),
    decided_by       uuid          REFERENCES users (id),
    decided_at       timestamptz,
    decision_note    text          CHECK (length(decision_note) <= 500),
    journal_entry_id uuid,
    created_at       timestamptz   NOT NULL DEFAULT now(),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id),
    CHECK ((status = 'pending') = (decided_at IS NULL AND decided_by IS NULL)),
    CHECK ((status = 'approved') = (journal_entry_id IS NOT NULL)),
    CHECK (status <> 'rejected' OR decision_note IS NOT NULL),
    CHECK ((status = 'withdrawn') = (decided_by IS NOT DISTINCT FROM requested_by AND status <> 'pending'))
);
CREATE INDEX journal_requests_status_idx ON journal_requests (company_id, status, created_at);

CREATE FUNCTION journal_requests_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'pending' THEN
            RAISE EXCEPTION 'A request starts waiting for approval' USING ERRCODE = 'check_violation';
        END IF;
        IF app_user_id() IS NOT NULL AND NEW.requested_by IS DISTINCT FROM app_user_id() THEN
            RAISE EXCEPTION 'A request is made by the person signed in' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'pending' THEN
        RAISE EXCEPTION 'This request is already %', OLD.status USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.company_id, NEW.entry_date, NEW.memo, NEW.lines, NEW.total, NEW.requested_by, NEW.created_at)
       IS DISTINCT FROM (OLD.company_id, OLD.entry_date, OLD.memo, OLD.lines, OLD.total, OLD.requested_by, OLD.created_at) THEN
        RAISE EXCEPTION 'What was asked does not change: withdraw it and ask again' USING ERRCODE = 'check_violation';
    END IF;
    IF app_user_id() IS NOT NULL AND NEW.decided_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A request is decided by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IN ('approved', 'rejected') AND NEW.decided_by = NEW.requested_by THEN
        RAISE EXCEPTION 'Someone other than the person who asked approves or rejects a journal' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'approved' AND NOT EXISTS (
        SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type = 'manual' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'An approved request names the manual journal it posted' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_requests_valid BEFORE INSERT OR UPDATE ON journal_requests
    FOR EACH ROW EXECUTE FUNCTION journal_requests_valid();

ALTER TABLE journal_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON journal_requests
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON journal_requests FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON journal_requests TO {{app_role}};

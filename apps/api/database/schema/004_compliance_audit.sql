-- Ledgerly core schema, part 4: tax-authority e-invoicing and the audit log.

CREATE TABLE einvoice_submissions (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid        NOT NULL REFERENCES companies (id),
    document_id       uuid        NOT NULL REFERENCES documents (id),
    authority         text        NOT NULL, -- ET_MOR, KE_KRA, UG_URA, TZ_TRA, RW_RRA, NG_NRS, SANDBOX
    status            text        NOT NULL DEFAULT 'queued'
                      CHECK (status IN ('queued', 'submitted', 'cleared', 'rejected', 'failed')),
    attempts          integer     NOT NULL DEFAULT 0,
    request_payload   jsonb,
    response_payload  jsonb,
    clearance_number  text,        -- invoice registration number / IRN / receipt number from the authority
    qr_payload        text,
    signature         text,
    last_error        text,
    submitted_at      timestamptz,
    cleared_at        timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (document_id, authority),
    CHECK (status <> 'cleared' OR clearance_number IS NOT NULL)
);
CREATE INDEX einvoice_pending_idx ON einvoice_submissions (company_id, status) WHERE status IN ('queued', 'failed');

-- Append-only: who did what, with before/after values.
CREATE TABLE audit_log (
    id           bigserial PRIMARY KEY,
    company_id   uuid        NOT NULL REFERENCES companies (id),
    user_id      uuid        REFERENCES users (id),
    actor_type   text        NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user', 'system', 'api_client', 'ai_agent')),
    action       text        NOT NULL, -- created, updated, posted, voided, reversed, login, permission_changed ...
    subject_type text        NOT NULL,
    subject_id   uuid,
    before       jsonb,
    after        jsonb,
    ip           inet,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_log_subject_idx ON audit_log (company_id, subject_type, subject_id);

CREATE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'The audit log is append-only' USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER audit_log_no_changes
    BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();

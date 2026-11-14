-- Ledgerly core schema, part 10: one-time links sent by email (SECURITY.md S3).
--
-- user_email_tokens holds the links for confirming an email address and for resetting a password:
--   - only a SHA-256 hash of the token is stored, so reading the table gives nothing that can be used;
--   - a token works once (used_at) and only until expires_at; the application takes it with a single UPDATE,
--     so two requests with the same link cannot both succeed.
--
-- Like user_recovery_codes (009) it belongs to a user, not a company, and is used before anyone is signed in.

CREATE TABLE user_email_tokens (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    purpose    text        NOT NULL CHECK (purpose IN ('verify_email', 'reset_password')),
    token_hash text        NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    used_at    timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX user_email_tokens_user ON user_email_tokens (user_id, purpose);

ALTER TABLE user_email_tokens ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_tokens_own ON user_email_tokens
    USING ((SELECT app_user_id()) IS NULL OR user_id = (SELECT app_user_id()))
    WITH CHECK ((SELECT app_user_id()) IS NULL OR user_id = (SELECT app_user_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON user_email_tokens TO {{app_role}};
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {{schema}} TO {{app_role}};

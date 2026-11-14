-- Ledgerly core schema, part 9: authenticator codes at sign-in (SECURITY.md S2).
--
-- users.mfa_secret (001) holds the authenticator secret, encrypted by the application. This file adds:
--   mfa_confirmed_at   set when the user proved the authenticator works; only then is a code asked at sign-in
--   mfa_last_step      the 30-second step of the last accepted code: a code is never accepted twice
--   user_recovery_codes   10 one-time codes for a lost phone, stored as keyed hashes, never readable
--
-- user_recovery_codes belongs to a user, not a company, so it has no company_id. Like users (008 §4) it is
-- readable by the user it belongs to, and by sign-in, which runs before any user is in the context.

ALTER TABLE users
    ADD COLUMN mfa_confirmed_at timestamptz,
    ADD COLUMN mfa_last_step    bigint,
    ADD CONSTRAINT users_mfa_confirmed_needs_secret CHECK (mfa_confirmed_at IS NULL OR mfa_secret IS NOT NULL);

CREATE TABLE user_recovery_codes (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    code_hash  text        NOT NULL,
    used_at    timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, code_hash)
);

ALTER TABLE user_recovery_codes ENABLE ROW LEVEL SECURITY;

CREATE POLICY recovery_codes_own ON user_recovery_codes
    USING ((SELECT app_user_id()) IS NULL OR user_id = (SELECT app_user_id()))
    WITH CHECK ((SELECT app_user_id()) IS NULL OR user_id = (SELECT app_user_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON user_recovery_codes TO {{app_role}};
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {{schema}} TO {{app_role}};

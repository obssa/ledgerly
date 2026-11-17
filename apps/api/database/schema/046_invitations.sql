-- Ledgerly core schema, part 46: invitation links (Module 11).
--
-- An owner or admin invites someone by email: an 'invited' membership (001, 008) and a one-time link to accept it.
-- Someone without a Ledgerly account gets one from the invitation, without a usable password until they accept and
-- choose it. Rules here:
--   1. Only a SHA-256 hash of the link's token is kept; the link works once, until it expires (at most 14 days).
--   2. A link is made only by an owner or admin of the invitation's company, only for an invitation still open.
--   3. The application role can only read its own company's links (hashes, useless without the token) and never
--      write them: invitation_issue() makes a link, invitation_lookup() reads one and invitation_take() uses one up,
--      each giving only what its caller needs. Looking up and taking work only outside any company's context, as the
--      person opening the link is not signed in.
--   4. Accepting is the invited person's own change (008 lets a user accept only their own invitation).

CREATE TABLE invitation_tokens (
    id            bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    uuid        NOT NULL REFERENCES companies (id),
    membership_id uuid        NOT NULL REFERENCES memberships (id) ON DELETE CASCADE,
    token_hash    text        NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    new_account   boolean     NOT NULL,
    expires_at    timestamptz NOT NULL,
    used_at       timestamptz,
    created_by    uuid        NOT NULL REFERENCES users (id),
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invitation_tokens_membership_idx ON invitation_tokens (membership_id);

CREATE FUNCTION invitation_issue(p_membership_id uuid, p_token_hash text, p_new_account boolean, p_expires_at timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF coalesce(app_member_role(app_company_id()), '') NOT IN ('owner', 'admin') THEN
        RAISE EXCEPTION 'Only an owner or admin can invite users' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM memberships WHERE id = p_membership_id AND company_id = app_company_id() AND status = 'invited') THEN
        RAISE EXCEPTION 'A link is made for an open invitation of this company' USING ERRCODE = 'check_violation';
    END IF;
    IF p_expires_at > now() + interval '14 days' OR p_expires_at <= now() THEN
        RAISE EXCEPTION 'An invitation link lasts up to 14 days' USING ERRCODE = 'check_violation';
    END IF;
    DELETE FROM invitation_tokens WHERE membership_id = p_membership_id;   -- a new link replaces the last one
    INSERT INTO invitation_tokens (company_id, membership_id, token_hash, new_account, expires_at, created_by)
        VALUES (app_company_id(), p_membership_id, p_token_hash, p_new_account, p_expires_at, app_user_id());
END $$;

CREATE FUNCTION invitation_lookup(p_token_hash text)
RETURNS TABLE (company_name text, role text, email text, name text, new_account boolean, expires_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT coalesce(c.trading_name, c.legal_name), m.role, u.email, u.name, t.new_account, t.expires_at
      FROM invitation_tokens t JOIN memberships m ON m.id = t.membership_id
      JOIN companies c ON c.id = m.company_id JOIN users u ON u.id = m.user_id
     WHERE t.token_hash = p_token_hash AND t.used_at IS NULL AND t.expires_at > now() AND m.status = 'invited'
       AND app_company_id() IS NULL
$$;

CREATE FUNCTION invitation_take(p_token_hash text)
RETURNS TABLE (membership_id uuid, company_id uuid, user_id uuid, new_account boolean)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    UPDATE invitation_tokens t SET used_at = now()
      FROM memberships m
     WHERE t.token_hash = p_token_hash AND t.used_at IS NULL AND t.expires_at > now()
       AND m.id = t.membership_id AND m.status = 'invited' AND app_company_id() IS NULL
    RETURNING m.id, m.company_id, m.user_id, t.new_account
$$;

ALTER TABLE invitation_tokens ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invitation_tokens
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invitation_tokens FROM {{app_role}};
GRANT SELECT ON invitation_tokens TO {{app_role}};
REVOKE ALL ON FUNCTION invitation_issue(uuid, text, boolean, timestamptz) FROM PUBLIC;
REVOKE ALL ON FUNCTION invitation_lookup(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION invitation_take(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION invitation_issue(uuid, text, boolean, timestamptz) TO {{app_role}};
GRANT EXECUTE ON FUNCTION invitation_lookup(text) TO {{app_role}};
GRANT EXECUTE ON FUNCTION invitation_take(text) TO {{app_role}};

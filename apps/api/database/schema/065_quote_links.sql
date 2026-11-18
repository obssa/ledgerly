-- Ledgerly core schema, part 65: quotes accepted online (Module 2).
--
-- A quote emailed to the customer carries a link to see it and accept or decline it, without an account. Rules, as for
-- invitation links (046):
--   1. Only a SHA-256 hash of the link's token is kept. A link is made for a quote the company has sent, lasts up to 90
--      days, and a new one replaces the quote's unanswered links.
--   2. It is answered once, before it expires, with the name of the person answering.
--   3. The application role reads its own company's links (hashes, useless without the token) and never writes them:
--      quote_link_issue() makes one inside the company; quote_link_lookup() and quote_link_answer() work only outside
--      any company's context, as the customer opening the link is not signed in, and give only the quote it is for.

CREATE TABLE quote_links (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  uuid        NOT NULL REFERENCES companies (id),
    document_id uuid        NOT NULL REFERENCES documents (id),
    token_hash  text        NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    expires_at  timestamptz NOT NULL,
    answered_at timestamptz,
    answer      text        CHECK (answer IN ('accepted', 'declined')),
    answered_by text        CHECK (length(trim(answered_by)) BETWEEN 2 AND 120),
    created_by  uuid        NOT NULL REFERENCES users (id),
    created_at  timestamptz NOT NULL DEFAULT now(),
    CHECK ((answered_at IS NULL) = (answer IS NULL) AND (answer IS NULL) = (answered_by IS NULL))
);
CREATE INDEX quote_links_document ON quote_links (company_id, document_id);

CREATE FUNCTION quote_link_issue(p_document_id uuid, p_token_hash text, p_expires_at timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF app_company_id() IS NULL OR app_user_id() IS NULL OR app_member_role(app_company_id()) IS NULL THEN
        RAISE EXCEPTION 'A quote link is made by a member of the quote''s company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM documents WHERE id = p_document_id AND company_id = app_company_id() AND type = 'quote' AND status = 'sent') THEN
        RAISE EXCEPTION 'A link is made for a quote the company has sent' USING ERRCODE = 'check_violation';
    END IF;
    IF p_expires_at > now() + interval '90 days' OR p_expires_at <= now() THEN
        RAISE EXCEPTION 'A quote link lasts up to 90 days' USING ERRCODE = 'check_violation';
    END IF;
    DELETE FROM quote_links WHERE company_id = app_company_id() AND document_id = p_document_id AND answered_at IS NULL;
    INSERT INTO quote_links (company_id, document_id, token_hash, expires_at, created_by)
        VALUES (app_company_id(), p_document_id, p_token_hash, p_expires_at, app_user_id());
END $$;

CREATE FUNCTION quote_link_lookup(p_token_hash text)
RETURNS TABLE (company_id uuid, document_id uuid, expires_at timestamptz, answered_at timestamptz, answer text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT l.company_id, l.document_id, l.expires_at, l.answered_at, l.answer
      FROM quote_links l
     WHERE l.token_hash = p_token_hash AND l.expires_at > now() AND app_company_id() IS NULL
$$;

CREATE FUNCTION quote_link_answer(p_token_hash text, p_answer text, p_name text)
RETURNS TABLE (company_id uuid, document_id uuid)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    UPDATE quote_links l SET answered_at = now(), answer = p_answer, answered_by = trim(p_name)
     WHERE l.token_hash = p_token_hash AND l.answered_at IS NULL AND l.expires_at > now() AND app_company_id() IS NULL
    RETURNING l.company_id, l.document_id
$$;

ALTER TABLE quote_links ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON quote_links
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON quote_links FROM {{app_role}};
GRANT SELECT ON quote_links TO {{app_role}};
REVOKE ALL ON FUNCTION quote_link_issue(uuid, text, timestamptz) FROM PUBLIC;
REVOKE ALL ON FUNCTION quote_link_lookup(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION quote_link_answer(text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION quote_link_issue(uuid, text, timestamptz) TO {{app_role}};
GRANT EXECUTE ON FUNCTION quote_link_lookup(text) TO {{app_role}};
GRANT EXECUTE ON FUNCTION quote_link_answer(text, text, text) TO {{app_role}};

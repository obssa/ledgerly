-- Ledgerly core schema, part 99: invoices seen online (Module 2).
--
-- A posted invoice can be shared with the customer as a link, sent by WhatsApp (or pasted anywhere): the link shows that
-- invoice, what is still due on it, and gives its PDF, without an account. Nothing can be done through it; paying stays
-- with the company's own payment details. Rules, as for quote links (065):
--   1. Only a SHA-256 hash of the link's token is kept. A link is made by a member of the company for one of its posted
--      invoices, and lasts up to 90 days. Several links can be live at once (one sent by email, one by WhatsApp).
--   2. The application role reads its own company's links (hashes, useless without the token) and never writes them:
--      invoice_link_issue() makes one inside the company; invoice_link_lookup() works only outside any company's
--      context, as the customer opening the link is not signed in, and gives only the invoice it is for.

CREATE TABLE invoice_links (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  uuid        NOT NULL REFERENCES companies (id),
    document_id uuid        NOT NULL REFERENCES documents (id),
    token_hash  text        NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    expires_at  timestamptz NOT NULL,
    created_by  uuid        NOT NULL REFERENCES users (id),
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_links_document ON invoice_links (company_id, document_id);

CREATE FUNCTION invoice_link_issue(p_document_id uuid, p_token_hash text, p_expires_at timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF app_company_id() IS NULL OR app_user_id() IS NULL OR app_member_role(app_company_id()) IS NULL THEN
        RAISE EXCEPTION 'An invoice link is made by a member of the invoice''s company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM documents WHERE id = p_document_id AND company_id = app_company_id() AND type = 'invoice'
                                             AND status IN ('posted', 'part_paid', 'paid')) THEN
        RAISE EXCEPTION 'A link is made for a posted invoice of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF p_expires_at > now() + interval '90 days' OR p_expires_at <= now() THEN
        RAISE EXCEPTION 'An invoice link lasts up to 90 days' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO invoice_links (company_id, document_id, token_hash, expires_at, created_by)
        VALUES (app_company_id(), p_document_id, p_token_hash, p_expires_at, app_user_id());
END $$;

CREATE FUNCTION invoice_link_lookup(p_token_hash text)
RETURNS TABLE (company_id uuid, document_id uuid, expires_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT l.company_id, l.document_id, l.expires_at
      FROM invoice_links l
     WHERE l.token_hash = p_token_hash AND l.expires_at > now() AND app_company_id() IS NULL
$$;

ALTER TABLE invoice_links ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invoice_links
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invoice_links FROM {{app_role}};
GRANT SELECT ON invoice_links TO {{app_role}};
REVOKE ALL ON FUNCTION invoice_link_issue(uuid, text, timestamptz) FROM PUBLIC;
REVOKE ALL ON FUNCTION invoice_link_lookup(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION invoice_link_issue(uuid, text, timestamptz) TO {{app_role}};
GRANT EXECUTE ON FUNCTION invoice_link_lookup(text) TO {{app_role}};

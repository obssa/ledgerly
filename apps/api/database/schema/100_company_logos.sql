-- Ledgerly core schema, part 100: the company's logo (Module 1).
--
-- A company can have a logo on its invoices and quotes: on the page and its print view, on the invoice or quote the
-- customer opens from a link, and on the PDF where the host can draw images. It is kept apart from the company row,
-- which is read on every request. Rules:
--   1. One logo per company: a PNG or JPEG (kept as base64 text), its first bytes those of its type, up to 256 KB.
--   2. It is set, changed or removed by an owner or admin of the company only (as the company's settings, 008).

CREATE TABLE company_logos (
    company_id    uuid        PRIMARY KEY REFERENCES companies (id),
    content_type  text        NOT NULL CHECK (content_type IN ('image/png', 'image/jpeg')),
    image_base64  text        NOT NULL CHECK (image_base64 ~ '^[A-Za-z0-9+/]+={0,2}$' AND length(image_base64) BETWEEN 44 AND 349528),   -- 32 B to 256 KB
    updated_by    uuid        NOT NULL REFERENCES users (id),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((content_type = 'image/png' AND substring(decode(substring(image_base64 FROM 1 FOR 12), 'base64') FROM 1 FOR 8) = '\x89504e470d0a1a0a'::bytea)
        OR (content_type = 'image/jpeg' AND substring(decode(substring(image_base64 FROM 1 FOR 12), 'base64') FROM 1 FOR 3) = '\xffd8ff'::bytea))
);

-- Rule 2.
CREATE FUNCTION company_logos_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    company uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.company_id ELSE NEW.company_id END;
BEGIN
    IF row_security_active('company_logos') AND coalesce(app_member_role(company), '') NOT IN ('owner', 'admin') THEN
        RAISE EXCEPTION 'Only an owner or admin changes the company''s logo' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.company_id IS DISTINCT FROM OLD.company_id THEN
        RAISE EXCEPTION 'A logo stays its company''s' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER company_logos_valid BEFORE INSERT OR UPDATE OR DELETE ON company_logos FOR EACH ROW EXECUTE FUNCTION company_logos_valid();

ALTER TABLE company_logos ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON company_logos
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON company_logos FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON company_logos TO {{app_role}};

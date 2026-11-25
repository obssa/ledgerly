-- Evenbooks core schema, part 14: locations and dimensions (Module 1).
--
-- Three kinds a company tags its transactions with, so reports can be cut by them:
--   location  a branch, shop or site          (journal_lines.location_id, documents.location_id)
--   class     a department or line of business (journal_lines.class_id)
--   project   a job, contract or project       (journal_lines.project_id)
-- A dimension is turned off, never deleted, so old entries keep their meaning. A line may name only a dimension of
-- its own company and of the right kind. Whether it is still active is checked by the API for new entries; the
-- database does not refuse an inactive one, so an old entry can always be reversed.

CREATE TABLE dimensions (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    kind        text        NOT NULL CHECK (kind IN ('location', 'class', 'project')),
    code        text        NOT NULL CHECK (code ~ '^[A-Za-z0-9._-]{1,20}$'),
    name        text        NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    is_active   boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, kind, code)
);

CREATE FUNCTION dimensions_fixed_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.company_id, NEW.kind, NEW.code) IS DISTINCT FROM (OLD.company_id, OLD.kind, OLD.code) THEN
        RAISE EXCEPTION 'The company, kind and code of a dimension never change; add a new one instead'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER dimensions_fixed_kind BEFORE UPDATE ON dimensions FOR EACH ROW EXECUTE FUNCTION dimensions_fixed_kind();

-- True when p_id is a dimension of this company and kind (or p_id is NULL). SECURITY DEFINER: the answer must not
-- depend on what the caller's row-level security context happens to show.
CREATE FUNCTION dimension_belongs(p_company_id uuid, p_kind text, p_id uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT p_id IS NULL OR EXISTS (SELECT 1 FROM dimensions WHERE id = p_id AND company_id = p_company_id AND kind = p_kind)
$$;
REVOKE ALL ON FUNCTION dimension_belongs(uuid, text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dimension_belongs(uuid, text, uuid) TO {{app_role}};

CREATE FUNCTION journal_lines_dimensions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT (dimension_belongs(NEW.company_id, 'location', NEW.location_id)
            AND dimension_belongs(NEW.company_id, 'class', NEW.class_id)
            AND dimension_belongs(NEW.company_id, 'project', NEW.project_id)) THEN
        RAISE EXCEPTION 'A journal line names a location, class or project that is not one of this company''s'
            USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER journal_lines_dimensions BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_lines_dimensions();

CREATE FUNCTION documents_location() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT dimension_belongs(NEW.company_id, 'location', NEW.location_id) THEN
        RAISE EXCEPTION 'A document names a location that is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_location BEFORE INSERT OR UPDATE OF location_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_location();

ALTER TABLE dimensions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON dimensions
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
-- Turned off, not deleted.
GRANT SELECT, INSERT, UPDATE ON dimensions TO {{app_role}};

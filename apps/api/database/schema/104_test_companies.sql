-- Evenbooks core schema, part 104: test companies.
--
-- A test company is a real company (real members with their own sign-in, real books, tax reminders, a subscription)
-- that the operator keeps for trying Evenbooks out. It is not the shared demo (018): nobody signs in to it without an
-- account of their own. What it has that other companies do not: its owners may give it empty books (a new company,
-- the old one archived and kept, read-only) and fill those with the sample books, as the demo's owner can (101).
--   1. Only the owner role marks a company as a test company (the operator, `ledgerly:test-company`); the application
--      can only read whether the company it is in is one.
--   2. test_company_fresh_start_begin(), by an active owner from inside a test company, names the company about to be
--      set up and records it; at most once a minute per test company.
--   3. test_company_fresh_start_finish(), by the same owner once the old company is archived and the new one set up in
--      the same organisation: every other active member of the old company joins the new one with the same role and
--      permissions, the new company is a test company, and the start is closed. The old company keeps its mark (it
--      is archived: nothing more happens there).

CREATE TABLE test_companies (
    company_id uuid        PRIMARY KEY REFERENCES companies (id) DEFERRABLE INITIALLY DEFERRED,
    marked_by  text        NOT NULL CHECK (length(marked_by) BETWEEN 1 AND 120),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE test_company_fresh_starts (
    company_id          uuid        PRIMARY KEY REFERENCES companies (id) DEFERRABLE INITIALLY DEFERRED,
    previous_company_id uuid        NOT NULL REFERENCES companies (id),
    requested_by        uuid        NOT NULL REFERENCES users (id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    finished_at         timestamptz
);
CREATE INDEX test_company_fresh_starts_previous ON test_company_fresh_starts (previous_company_id, created_at);

CREATE FUNCTION test_company_fresh_start_begin() RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    me    uuid := app_user_id();
    old   uuid := app_company_id();
    fresh uuid := gen_random_uuid();
BEGIN
    IF me IS NULL OR old IS NULL
       OR NOT EXISTS (SELECT 1 FROM test_companies WHERE company_id = old)
       OR NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = old AND user_id = me AND role = 'owner' AND status = 'active')
       OR NOT EXISTS (SELECT 1 FROM companies WHERE id = old AND status <> 'archived') THEN
        RAISE EXCEPTION 'Only an owner of a test company starts it fresh, from inside it' USING ERRCODE = 'insufficient_privilege';
    END IF;
    -- Once a minute along the chain: neither from this company, nor from a company this one was just started fresh as.
    IF EXISTS (SELECT 1 FROM test_company_fresh_starts
                WHERE (previous_company_id = old OR company_id = old) AND created_at > now() - interval '1 minute') THEN
        RAISE EXCEPTION 'This test company was just started fresh: try again in a minute' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO test_company_fresh_starts (company_id, previous_company_id, requested_by) VALUES (fresh, old, me);
    RETURN fresh;
END $$;

CREATE FUNCTION test_company_fresh_start_finish(p_company_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    me    uuid := app_user_id();
    start test_company_fresh_starts%ROWTYPE;
BEGIN
    SELECT * INTO start FROM test_company_fresh_starts WHERE company_id = p_company_id AND finished_at IS NULL FOR UPDATE;
    IF NOT FOUND OR start.requested_by IS DISTINCT FROM me THEN
        RAISE EXCEPTION 'No open fresh start of a test company by this user for that company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM companies n JOIN companies o ON o.id = start.previous_company_id
         WHERE n.id = p_company_id AND n.organisation_id = o.organisation_id AND n.status <> 'archived' AND o.status = 'archived'
    ) OR NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = p_company_id AND user_id = me AND role = 'owner' AND status = 'active') THEN
        RAISE EXCEPTION 'The new company must be set up in the same organisation, with its owner, and the old one archived first'
            USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO memberships (company_id, user_id, role, permissions, status)
    SELECT p_company_id, m.user_id, m.role, m.permissions, 'active'
      FROM memberships m
     WHERE m.company_id = start.previous_company_id AND m.status = 'active'
       AND NOT EXISTS (SELECT 1 FROM memberships n WHERE n.company_id = p_company_id AND n.user_id = m.user_id);
    INSERT INTO test_companies (company_id, marked_by) VALUES (p_company_id, 'fresh start of ' || start.previous_company_id::text);
    UPDATE test_company_fresh_starts SET finished_at = now() WHERE company_id = p_company_id;
END $$;

ALTER TABLE test_companies ENABLE ROW LEVEL SECURITY;
CREATE POLICY test_companies_read ON test_companies FOR SELECT USING (company_id = (SELECT app_company_id()));
REVOKE ALL ON test_companies FROM {{app_role}};
GRANT SELECT ON test_companies TO {{app_role}};

ALTER TABLE test_company_fresh_starts ENABLE ROW LEVEL SECURITY;
CREATE POLICY test_company_fresh_starts_read ON test_company_fresh_starts FOR SELECT
    USING (company_id = (SELECT app_company_id()) OR previous_company_id = (SELECT app_company_id()));
REVOKE ALL ON test_company_fresh_starts FROM {{app_role}};
GRANT SELECT ON test_company_fresh_starts TO {{app_role}};

REVOKE ALL ON FUNCTION test_company_fresh_start_begin() FROM PUBLIC;
REVOKE ALL ON FUNCTION test_company_fresh_start_finish(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION test_company_fresh_start_begin() TO {{app_role}};
GRANT EXECUTE ON FUNCTION test_company_fresh_start_finish(uuid) TO {{app_role}};

-- Ledgerly core schema, part 101: a fresh start for the demo.
--
-- The demo's owner can give the shared demo accounts an empty company: a new demo company is set up, the old one is
-- archived (kept, read-only: nothing in the books is ever deleted), and every demo account moves to the new one.
-- What 018 guarantees still holds: the application role never writes demo_accounts, and a demo account never reaches a
-- company that is not a demo company. The only additions:
--   1. demo_fresh_start_begin() (the demo owner, inside the demo company) names the company about to be set up and
--      records it; at most one a minute.
--   2. While that start is open, the demo accounts of the old demo company may become members of the new one (the
--      demo owner, as its first owner, through the usual company setup).
--   3. demo_fresh_start_finish() (the same owner, once the old company is archived) adds every other demo account to
--      the new company with its role, moves the demo accounts there and closes the start.
--   4. A demo account may keep memberships of archived companies (the earlier demo companies); any other company
--      is still refused.

CREATE TABLE demo_fresh_starts (
    company_id          uuid        PRIMARY KEY REFERENCES companies (id) DEFERRABLE INITIALLY DEFERRED,
    previous_company_id uuid        NOT NULL REFERENCES companies (id),
    requested_by        uuid        NOT NULL REFERENCES users (id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    finished_at         timestamptz
);

CREATE FUNCTION demo_fresh_start_begin() RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    me      uuid := app_user_id();
    demo    uuid := app_company_id();
    fresh   uuid := gen_random_uuid();
BEGIN
    IF me IS NULL OR demo IS NULL OR NOT EXISTS (
        SELECT 1 FROM demo_accounts WHERE user_id = me AND company_id = demo AND role = 'owner'
    ) THEN
        RAISE EXCEPTION 'Only the demo''s owner starts the demo fresh, from the demo company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF EXISTS (SELECT 1 FROM demo_fresh_starts WHERE created_at > now() - interval '1 minute') THEN
        RAISE EXCEPTION 'The demo was just started fresh: try again in a minute' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO demo_fresh_starts (company_id, previous_company_id, requested_by) VALUES (fresh, demo, me);
    RETURN fresh;
END $$;

CREATE FUNCTION demo_fresh_start_finish(p_company_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    me    uuid := app_user_id();
    start demo_fresh_starts%ROWTYPE;
BEGIN
    SELECT * INTO start FROM demo_fresh_starts WHERE company_id = p_company_id AND finished_at IS NULL FOR UPDATE;
    IF NOT FOUND OR start.requested_by IS DISTINCT FROM me THEN
        RAISE EXCEPTION 'No open fresh start of the demo by this user for that company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM companies n JOIN companies o ON o.id = start.previous_company_id
         WHERE n.id = p_company_id AND n.organisation_id = o.organisation_id AND n.status <> 'archived' AND o.status = 'archived'
    ) THEN
        RAISE EXCEPTION 'The new demo company must be set up in the same organisation and the old one archived first'
            USING ERRCODE = 'check_violation';
    END IF;

    INSERT INTO memberships (company_id, user_id, role, status)
    SELECT p_company_id, d.user_id, d.role, 'active'
      FROM demo_accounts d
     WHERE d.company_id = start.previous_company_id
       AND NOT EXISTS (SELECT 1 FROM memberships m WHERE m.company_id = p_company_id AND m.user_id = d.user_id);
    UPDATE demo_accounts SET company_id = p_company_id WHERE company_id = start.previous_company_id;
    UPDATE demo_fresh_starts SET finished_at = now() WHERE company_id = p_company_id;
END $$;

-- 2. A demo account joins only its demo company, or the company of an open fresh start from it.
CREATE OR REPLACE FUNCTION memberships_demo_stays_home() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM demo_accounts d WHERE d.user_id = NEW.user_id AND d.company_id <> NEW.company_id
                  AND NOT EXISTS (SELECT 1 FROM demo_fresh_starts f
                                   WHERE f.company_id = NEW.company_id AND f.previous_company_id = d.company_id
                                     AND f.finished_at IS NULL)) THEN
        RAISE EXCEPTION 'A demo account cannot join another company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

-- 4. Memberships of archived companies (earlier demo companies) do not stop a demo account belonging to its company.
CREATE OR REPLACE FUNCTION demo_accounts_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM memberships
                    WHERE company_id = NEW.company_id AND user_id = NEW.user_id AND role = NEW.role AND status = 'active') THEN
        RAISE EXCEPTION 'A demo account must be an active member of the demo company with its role'
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM memberships m JOIN companies c ON c.id = m.company_id
                WHERE m.user_id = NEW.user_id AND m.company_id <> NEW.company_id AND c.status <> 'archived') THEN
        RAISE EXCEPTION 'A demo account belongs to the demo company only' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

ALTER TABLE demo_fresh_starts ENABLE ROW LEVEL SECURITY;
CREATE POLICY demo_fresh_starts_read ON demo_fresh_starts FOR SELECT
    USING (company_id = (SELECT app_company_id()) OR previous_company_id = (SELECT app_company_id()));
REVOKE ALL ON demo_fresh_starts FROM {{app_role}};
GRANT SELECT ON demo_fresh_starts TO {{app_role}};
REVOKE ALL ON FUNCTION demo_fresh_start_begin() FROM PUBLIC;
REVOKE ALL ON FUNCTION demo_fresh_start_finish(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION demo_fresh_start_begin() TO {{app_role}};
GRANT EXECUTE ON FUNCTION demo_fresh_start_finish(uuid) TO {{app_role}};

-- Ledgerly core schema, part 8: who may change access, companies and credentials (security review).
--
-- 007 isolates one company from another. This file closes the gaps INSIDE a company and around sign-in:
--   1. Only an owner or admin can add, change or remove members. Only an owner can grant or take the owner role.
--      A company always keeps at least one active owner.
--   2. Only an owner or admin can change company settings or reopen a closed period.
--      An accountant can close a period (move the lock forward) and nothing else on the company.
--   3. Only the organisation's creator, or an owner/admin of one of its companies, can add a company to it.
--   4. User records (email, password hash, authenticator secret) are visible only to the user and to
--      members of the company being worked in.
--   5. The audit-chain check works only for the caller's own company.
--
-- These rules apply to the application role. The owner role (migrations, billing, staff console) is not
-- subject to row-level security; the staff console has its own rules and its own log.
--
-- Honest limit: the tenant context is a transaction setting the application sets. It protects against
-- application mistakes (a missing filter, a wrong id). It does NOT protect against arbitrary SQL run on
-- the application's connection, because that SQL could set the context itself. So: no string-built SQL,
-- ever (see SECURITY.md).

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Functions that run with the owner's rights never resolve names through a temporary schema.
ALTER FUNCTION ledger_assert_can_post() SET search_path = public, pg_temp;
ALTER FUNCTION ledger_apply_balances()  SET search_path = public, pg_temp;
ALTER FUNCTION audit_log_chain()        SET search_path = public, pg_temp;

-- ---------------------------------------------------------------------------------------------------
-- Helpers. Each answers one narrow question about the CALLER, so running with the owner's rights leaks nothing.
-- ---------------------------------------------------------------------------------------------------

-- The caller's role in a company ('owner', 'admin', ...), or NULL when not an active member.
CREATE FUNCTION app_member_role(p_company_id uuid) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT role FROM memberships
     WHERE company_id = p_company_id AND user_id = app_user_id() AND status = 'active'
$$;

CREATE FUNCTION app_company_has_members(p_company_id uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM memberships WHERE company_id = p_company_id)
$$;

CREATE FUNCTION app_can_add_company(p_organisation_id uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM organisations o WHERE o.id = p_organisation_id AND o.created_by = app_user_id())
        OR EXISTS (SELECT 1 FROM memberships m JOIN companies c ON c.id = m.company_id
                    WHERE c.organisation_id = p_organisation_id AND m.user_id = app_user_id()
                      AND m.status = 'active' AND m.role IN ('owner', 'admin'))
$$;

-- For inviting someone who already has a Ledgerly account: returns only the id, only to an owner or admin
-- of the current company.
CREATE FUNCTION app_user_id_for_invite(p_email text) RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF coalesce(app_member_role(app_company_id()), '') NOT IN ('owner', 'admin') THEN
        RAISE EXCEPTION 'Only an owner or admin can invite users' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN (SELECT id FROM users WHERE lower(email) = lower(p_email));
END $$;

-- ---------------------------------------------------------------------------------------------------
-- 1. Memberships
-- ---------------------------------------------------------------------------------------------------
DROP POLICY membership_access ON memberships;

CREATE POLICY membership_read ON memberships FOR SELECT
    USING (company_id = (SELECT app_company_id()) OR user_id = (SELECT app_user_id()));

-- An owner adds anyone; an admin adds anyone except an owner; the user setting up a new company becomes
-- its first owner.
CREATE POLICY membership_add ON memberships FOR INSERT
    WITH CHECK (
        company_id = (SELECT app_company_id())
        AND (
            app_member_role(company_id) = 'owner'
            OR (app_member_role(company_id) = 'admin' AND role <> 'owner')
            OR (user_id = (SELECT app_user_id()) AND role = 'owner' AND status = 'active'
                AND NOT app_company_has_members(company_id))
        )
    );

-- Owners and admins manage the current company's members; a user may touch their own row (the trigger
-- below limits that to accepting an invitation).
CREATE POLICY membership_change ON memberships FOR UPDATE
    USING ((company_id = (SELECT app_company_id()) AND app_member_role(company_id) IN ('owner', 'admin'))
           OR user_id = (SELECT app_user_id()))
    WITH CHECK ((company_id = (SELECT app_company_id()) AND app_member_role(company_id) IN ('owner', 'admin'))
                OR user_id = (SELECT app_user_id()));

CREATE POLICY membership_remove ON memberships FOR DELETE
    USING (company_id = (SELECT app_company_id()) AND app_member_role(company_id) IN ('owner', 'admin'));

CREATE FUNCTION memberships_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    me text;
BEGIN
    IF NOT row_security_active('memberships') THEN  -- owner role: migrations, staff console
        RETURN coalesce(NEW, OLD);
    END IF;
    me := coalesce(app_member_role(OLD.company_id), '');

    IF TG_OP = 'UPDATE' AND (NEW.company_id <> OLD.company_id OR NEW.user_id <> OLD.user_id) THEN
        RAISE EXCEPTION 'A membership cannot be moved to another company or user' USING ERRCODE = 'insufficient_privilege';
    END IF;

    IF me = 'owner' THEN
        RETURN coalesce(NEW, OLD);
    END IF;

    IF me = 'admin' THEN
        IF OLD.role = 'owner' OR (TG_OP = 'UPDATE' AND NEW.role = 'owner') THEN
            RAISE EXCEPTION 'Only an owner can grant, change or remove the owner role' USING ERRCODE = 'insufficient_privilege';
        END IF;
        RETURN coalesce(NEW, OLD);
    END IF;

    -- Not an owner or admin: the only thing a user can do is accept their own invitation.
    IF TG_OP = 'UPDATE' AND OLD.user_id = app_user_id()
       AND OLD.status = 'invited' AND NEW.status = 'active'
       AND NEW.role = OLD.role AND NEW.permissions = OLD.permissions THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Only an owner or admin can change users and roles' USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER memberships_guard BEFORE UPDATE OR DELETE ON memberships
    FOR EACH ROW EXECUTE FUNCTION memberships_guard();

-- Applies to everyone, staff included: a live company is never left without an active owner.
CREATE FUNCTION memberships_keep_an_owner() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF OLD.role = 'owner' AND OLD.status = 'active'
       AND EXISTS (SELECT 1 FROM companies WHERE id = OLD.company_id)  -- not the cascade from deleting the company
       AND NOT EXISTS (SELECT 1 FROM memberships
                        WHERE company_id = OLD.company_id AND role = 'owner' AND status = 'active') THEN
        RAISE EXCEPTION 'A company must keep at least one active owner' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;

CREATE TRIGGER memberships_keep_an_owner AFTER UPDATE OR DELETE ON memberships
    FOR EACH ROW EXECUTE FUNCTION memberships_keep_an_owner();

-- ---------------------------------------------------------------------------------------------------
-- 2 and 3. Companies and organisations
-- ---------------------------------------------------------------------------------------------------
DROP POLICY company_access ON companies;

CREATE POLICY company_read ON companies FOR SELECT
    USING (id = (SELECT app_company_id())
           OR id IN (SELECT company_id FROM memberships WHERE user_id = (SELECT app_user_id()) AND status = 'active'));

CREATE POLICY company_create ON companies FOR INSERT
    WITH CHECK (id = (SELECT app_company_id()) AND app_can_add_company(organisation_id));

CREATE POLICY company_change ON companies FOR UPDATE
    USING (id = (SELECT app_company_id()))
    WITH CHECK (id = (SELECT app_company_id()));

CREATE FUNCTION companies_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    me text;
BEGIN
    IF NEW.organisation_id <> OLD.organisation_id AND row_security_active('companies') THEN
        RAISE EXCEPTION 'A company cannot be moved to another organisation' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT row_security_active('companies') THEN
        RETURN NEW;
    END IF;

    me := coalesce(app_member_role(OLD.id), '');
    IF me IN ('owner', 'admin') THEN
        RETURN NEW;
    END IF;

    -- Closing a period (moving the lock forward) is everyday accounting work. Everything else on the
    -- company row, and reopening a period, is for an owner or admin.
    IF me IN ('accountant', 'custom')
       AND (to_jsonb(NEW) - 'books_closed_until' - 'updated_at') = (to_jsonb(OLD) - 'books_closed_until' - 'updated_at')
       AND NEW.books_closed_until IS NOT NULL
       AND (OLD.books_closed_until IS NULL OR NEW.books_closed_until >= OLD.books_closed_until) THEN
        RETURN NEW;
    END IF;

    IF NEW.books_closed_until IS DISTINCT FROM OLD.books_closed_until THEN
        RAISE EXCEPTION 'Only an owner or admin can reopen a closed period' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RAISE EXCEPTION 'Only an owner or admin can change company settings' USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER companies_guard BEFORE UPDATE ON companies
    FOR EACH ROW EXECUTE FUNCTION companies_guard();

DROP POLICY organisation_rename ON organisations;
CREATE POLICY organisation_rename ON organisations FOR UPDATE
    USING (id IN (SELECT organisation_id FROM companies WHERE id = (SELECT app_company_id()))
           AND app_member_role((SELECT app_company_id())) IN ('owner', 'admin'));

-- ---------------------------------------------------------------------------------------------------
-- 4. Users: a company's request can read only its own members. Sign-in, token checks and password reset
--    run before any user is in the context, and need to find a user by email or token.
-- ---------------------------------------------------------------------------------------------------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;

CREATE POLICY user_read ON users FOR SELECT
    USING ((SELECT app_user_id()) IS NULL
           OR id = (SELECT app_user_id())
           OR id IN (SELECT user_id FROM memberships WHERE company_id = (SELECT app_company_id())));

CREATE POLICY user_register ON users FOR INSERT WITH CHECK (true);

CREATE POLICY user_change_self ON users FOR UPDATE
    USING ((SELECT app_user_id()) IS NULL OR id = (SELECT app_user_id()))
    WITH CHECK ((SELECT app_user_id()) IS NULL OR id = (SELECT app_user_id()));

REVOKE DELETE ON users FROM ledgerly_app;  -- accounts are closed by a staff process, never deleted by a request
GRANT EXECUTE ON FUNCTION app_user_id_for_invite(text) TO ledgerly_app;

-- ---------------------------------------------------------------------------------------------------
-- 5. The audit-chain check: the application may verify only the company it is working in.
-- ---------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION audit_log_verify(p_company_id uuid) RETURNS bigint
LANGUAGE plpgsql STABLE SET search_path = public, pg_temp AS $$
DECLARE
    r        audit_log;
    expected text := NULL;
BEGIN
    IF row_security_active('audit_log') AND p_company_id IS DISTINCT FROM app_company_id() THEN
        RAISE EXCEPTION 'The audit log can be verified only for the current company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    FOR r IN SELECT * FROM audit_log WHERE company_id = p_company_id ORDER BY id LOOP  -- one partition
        IF r.prev_hash IS DISTINCT FROM expected OR r.row_hash IS DISTINCT FROM audit_log_row_digest(r) THEN
            RETURN r.id;
        END IF;
        expected := r.row_hash;
    END LOOP;
    RETURN NULL;
END $$;
ALTER FUNCTION audit_log_verify(uuid) SECURITY INVOKER;

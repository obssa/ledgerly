-- Evenbooks core schema, part 18: demo sign-in (one shared account per role in a demo company).
--
-- Which accounts are demo accounts is decided here, not by anything a request can change:
--   - only the owner role writes this table (the deploy's `ledgerly:demo-setup`); the application role can only read it,
--     so no request can turn a real account into a demo one or a demo account into another company's member;
--   - each row names the demo company, and the account must be an active member of it with the row's role.
-- The API offers demo sign-in only when DEMO_LOGINS=true (config/demo.php), and refuses password, authenticator,
-- email and new-company changes to these accounts, because every visitor shares them.
--
-- Read access is open (USING true): the rows say only which user ids are demo accounts, and sign-in reads them
-- before anyone is in the context. They hold no credential.

CREATE TABLE demo_accounts (
    user_id    uuid        PRIMARY KEY REFERENCES users (id),
    company_id uuid        NOT NULL REFERENCES companies (id),
    role       text        NOT NULL UNIQUE
               CHECK (role IN ('owner', 'admin', 'accountant', 'bookkeeper', 'sales', 'purchasing', 'approver', 'viewer')),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE FUNCTION demo_accounts_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM memberships
                    WHERE company_id = NEW.company_id AND user_id = NEW.user_id AND role = NEW.role AND status = 'active') THEN
        RAISE EXCEPTION 'A demo account must be an active member of the demo company with its role'
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM memberships WHERE user_id = NEW.user_id AND company_id <> NEW.company_id) THEN
        RAISE EXCEPTION 'A demo account belongs to the demo company only' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER demo_accounts_valid BEFORE INSERT OR UPDATE ON demo_accounts
    FOR EACH ROW EXECUTE FUNCTION demo_accounts_valid();

-- A demo account never joins a second company (every visitor shares it).
CREATE FUNCTION memberships_demo_stays_home() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM demo_accounts WHERE user_id = NEW.user_id AND company_id <> NEW.company_id) THEN
        RAISE EXCEPTION 'A demo account cannot join another company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER memberships_demo_stays_home BEFORE INSERT OR UPDATE ON memberships
    FOR EACH ROW EXECUTE FUNCTION memberships_demo_stays_home();

ALTER TABLE demo_accounts ENABLE ROW LEVEL SECURITY;
CREATE POLICY demo_accounts_read ON demo_accounts FOR SELECT USING (true);

REVOKE ALL ON demo_accounts FROM {{app_role}};
GRANT SELECT ON demo_accounts TO {{app_role}};

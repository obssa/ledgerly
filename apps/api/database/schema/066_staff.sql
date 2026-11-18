-- Ledgerly core schema, part 66: the staff console (SECURITY.md S8).
--
-- Ledgerly's own support staff sign in apart from customers: their own accounts, a password and an authenticator code
-- always, from allow-listed addresses only (checked by the API). To look into a customer's books they open a support
-- session for one company, with a reason, for up to two hours; inside it they only read (the API reads in a read-only
-- transaction, under row-level security for that company). Every staff step is in the staff log. Rules here:
--   1. Staff accounts are made and switched off only by the owner role (an operator, through the deploy system); the
--      application can never create one, read its password or secret directly, or change it outside the functions below.
--   2. A staff token, a setup link and every support session are checked by the database itself: the functions that
--      read across companies (the company list, opening a session) work only for a valid, unexpired token of an active
--      staff member. Only SHA-256 hashes of tokens and links are kept.
--   3. The staff log is append-only.

CREATE TABLE staff_users (
    id               uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    email            text        NOT NULL UNIQUE CHECK (email = lower(email) AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    name             text        NOT NULL CHECK (length(trim(name)) BETWEEN 2 AND 120),
    password         text,                       -- a bcrypt hash; none until the account is set up
    mfa_secret       text,                       -- encrypted by the API with its key; none until set up
    mfa_last_step    bigint,                     -- the last authenticator step used: a code works once
    setup_hash       text        UNIQUE CHECK (setup_hash ~ '^[0-9a-f]{64}$'),
    setup_expires_at timestamptz,
    active           boolean     NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now(),
    last_sign_in_at  timestamptz,
    CHECK ((setup_hash IS NULL) = (setup_expires_at IS NULL)),
    CHECK ((password IS NULL) = (mfa_secret IS NULL))
);

CREATE TABLE staff_tokens (
    id         bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    staff_id   uuid        NOT NULL REFERENCES staff_users (id),
    token_hash text        NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    expires_at timestamptz NOT NULL,
    revoked_at timestamptz,
    ip         text        CHECK (length(ip) <= 64),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (expires_at <= created_at + interval '8 hours')
);

CREATE TABLE staff_sessions (
    id         uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    staff_id   uuid        NOT NULL REFERENCES staff_users (id),
    company_id uuid        NOT NULL REFERENCES companies (id),
    reason     text        NOT NULL CHECK (length(trim(reason)) BETWEEN 10 AND 300),
    started_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    ended_at   timestamptz,
    CHECK (expires_at > started_at AND expires_at <= started_at + interval '2 hours')
);
CREATE INDEX staff_sessions_open ON staff_sessions (staff_id) WHERE ended_at IS NULL;

CREATE TABLE staff_log (
    id         bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    staff_id   uuid        REFERENCES staff_users (id),
    session_id uuid        REFERENCES staff_sessions (id),
    company_id uuid        REFERENCES companies (id),
    action     text        NOT NULL CHECK (action ~ '^[a-z_]{3,60}$'),
    detail     jsonb       NOT NULL DEFAULT '{}'::jsonb,
    ip         text        CHECK (length(ip) <= 64),
    at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX staff_log_at ON staff_log (at);

CREATE FUNCTION staff_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'The staff log is append-only' USING ERRCODE = 'insufficient_privilege';
END $$;
CREATE TRIGGER staff_log_no_changes BEFORE UPDATE OR DELETE ON staff_log FOR EACH ROW EXECUTE FUNCTION staff_log_append_only();

-- The staff member a token belongs to, while it is valid and they are active.
CREATE FUNCTION staff_token_check(p_token_hash text)
RETURNS TABLE (staff_id uuid, name text, email text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT s.id, s.name, s.email FROM staff_tokens t JOIN staff_users s ON s.id = t.staff_id
     WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now() AND s.active AND s.password IS NOT NULL
$$;

-- Sign-in: what the API checks the password and code against (never returned to anyone else).
CREATE FUNCTION staff_sign_in_find(p_email text)
RETURNS TABLE (staff_id uuid, password text, mfa_secret text, mfa_last_step bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT id, password, mfa_secret, mfa_last_step FROM staff_users
     WHERE email = lower(trim(p_email)) AND active AND password IS NOT NULL AND app_company_id() IS NULL
$$;

-- After a right password and code: the code's step is used up and a token of up to 8 hours is issued (the database's
-- own clock decides when it ends).
CREATE FUNCTION staff_sign_in_complete(p_staff_id uuid, p_step bigint, p_token_hash text, p_hours integer, p_ip text) RETURNS timestamptz
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    ends timestamptz := now() + make_interval(hours => least(greatest(p_hours, 1), 8));
BEGIN
    UPDATE staff_users SET mfa_last_step = p_step, last_sign_in_at = now()
     WHERE id = p_staff_id AND active AND (mfa_last_step IS NULL OR p_step > mfa_last_step);
    IF NOT FOUND THEN
        RAISE EXCEPTION 'That code was used already' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO staff_tokens (staff_id, token_hash, expires_at, ip) VALUES (p_staff_id, p_token_hash, ends, p_ip);
    RETURN ends;
END $$;

CREATE FUNCTION staff_sign_out(p_token_hash text) RETURNS void
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    UPDATE staff_tokens SET revoked_at = now() WHERE token_hash = p_token_hash AND revoked_at IS NULL
$$;

-- The setup link an operator's new account mails: once, before it expires, it sets the password and authenticator.
CREATE FUNCTION staff_setup_find(p_setup_hash text)
RETURNS TABLE (staff_id uuid, email text, name text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT id, email, name FROM staff_users WHERE setup_hash = p_setup_hash AND setup_expires_at > now() AND active
$$;

CREATE FUNCTION staff_setup_complete(p_setup_hash text, p_password text, p_mfa_secret text, p_step bigint) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    done uuid;
BEGIN
    IF p_password !~ '^\$2[aby]\$' OR length(p_mfa_secret) < 20 THEN
        RAISE EXCEPTION 'A staff password is kept only as a bcrypt hash, with an encrypted secret' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE staff_users SET password = p_password, mfa_secret = p_mfa_secret, mfa_last_step = p_step, setup_hash = NULL, setup_expires_at = NULL
     WHERE setup_hash = p_setup_hash AND setup_expires_at > now() AND active
    RETURNING id INTO done;
    RETURN done;
END $$;

-- The company list, for a valid staff token only: who the customers are, nothing of their books.
CREATE FUNCTION staff_companies(p_token_hash text, p_search text)
RETURNS TABLE (id uuid, legal_name text, trading_name text, country_code text, status text, created_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT c.id, c.legal_name, c.trading_name, c.country_code::text, c.status, c.created_at FROM companies c
     WHERE EXISTS (SELECT 1 FROM staff_token_check(p_token_hash))
       AND (coalesce(p_search, '') = '' OR c.legal_name ILIKE '%' || replace(replace(replace(p_search, '\', '\\'), '%', '\%'), '_', '\_') || '%'
            OR c.trading_name ILIKE '%' || replace(replace(replace(p_search, '\', '\\'), '%', '\%'), '_', '\_') || '%')
     ORDER BY c.legal_name LIMIT 50
$$;

-- A support session: one company, a reason, up to two hours, for a valid staff token; a new one ends the last.
CREATE FUNCTION staff_session_start(p_token_hash text, p_company_id uuid, p_reason text, p_minutes integer) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    who uuid;
    made uuid;
BEGIN
    SELECT staff_id INTO who FROM staff_token_check(p_token_hash);
    IF who IS NULL THEN
        RAISE EXCEPTION 'Sign in as staff first' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM companies WHERE id = p_company_id) THEN
        RAISE EXCEPTION 'No such company' USING ERRCODE = 'check_violation';
    END IF;
    IF p_minutes NOT BETWEEN 5 AND 120 THEN
        RAISE EXCEPTION 'A support session lasts 5 to 120 minutes' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE staff_sessions SET ended_at = now() WHERE staff_id = who AND ended_at IS NULL;
    INSERT INTO staff_sessions (staff_id, company_id, reason, expires_at) VALUES (who, p_company_id, trim(p_reason), now() + make_interval(mins => p_minutes))
    RETURNING id INTO made;
    RETURN made;
END $$;

-- The company an open session of this staff member reads, while it lasts.
CREATE FUNCTION staff_session_check(p_token_hash text, p_session_id uuid)
RETURNS TABLE (company_id uuid, expires_at timestamptz, reason text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT s.company_id, s.expires_at, s.reason FROM staff_sessions s
     WHERE s.id = p_session_id AND s.ended_at IS NULL AND s.expires_at > now()
       AND s.staff_id = (SELECT staff_id FROM staff_token_check(p_token_hash))
$$;

CREATE FUNCTION staff_session_end(p_token_hash text, p_session_id uuid) RETURNS void
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    UPDATE staff_sessions SET ended_at = now()
     WHERE id = p_session_id AND ended_at IS NULL AND staff_id = (SELECT staff_id FROM staff_token_check(p_token_hash))
$$;

-- No company may see any of these; the application reaches them only through the functions, and appends to the log.
ALTER TABLE staff_users ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY staff_log_append ON staff_log FOR INSERT WITH CHECK (true);
REVOKE ALL ON staff_users, staff_tokens, staff_sessions, staff_log FROM {{app_role}};
GRANT INSERT ON staff_log TO {{app_role}};

REVOKE ALL ON FUNCTION staff_token_check(text), staff_sign_in_find(text), staff_sign_in_complete(uuid, bigint, text, integer, text),
    staff_sign_out(text), staff_setup_find(text), staff_setup_complete(text, text, text, bigint), staff_companies(text, text),
    staff_session_start(text, uuid, text, integer), staff_session_check(text, uuid), staff_session_end(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION staff_token_check(text), staff_sign_in_find(text), staff_sign_in_complete(uuid, bigint, text, integer, text),
    staff_sign_out(text), staff_setup_find(text), staff_setup_complete(text, text, text, bigint), staff_companies(text, text),
    staff_session_start(text, uuid, text, integer), staff_session_check(text, uuid), staff_session_end(text, uuid) TO {{app_role}};

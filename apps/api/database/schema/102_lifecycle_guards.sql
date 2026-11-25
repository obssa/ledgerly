-- Evenbooks core schema, part 102: guards for lifecycles the database did not check.
--
-- 1. Projects and leases become state machines like the others (006): the list is in state_transitions and in
--    config/state_machines.php.
--      project: active <-> on_hold; either -> completed | cancelled; completed | cancelled -> active (reopened).
--               A finished project is never put straight on hold or swapped between completed and cancelled.
--      lease:   active -> ended, and ended is final.
-- 2. Things used once stay used: a recovery code, an email link (verify, reset).
-- 3. An authenticator's last accepted step only moves forward while the secret is the same, so a code is never
--    accepted twice even if the application slipped (staff accounts have had this rule since 095).
-- 4. Counters only count up: how many invoices or journals a recurring template has made.
-- 5. An assembly build's value is set once.

INSERT INTO state_transitions (machine, from_state, to_state, action) VALUES
    ('project', '',          'active',    'CreateProject'),
    ('project', '',          'on_hold',   'CreateProject'),
    ('project', '',          'completed', 'CreateProject'),
    ('project', '',          'cancelled', 'CreateProject'),
    ('project', 'active',    'on_hold',   'HoldProject'),
    ('project', 'on_hold',   'active',    'ResumeProject'),
    ('project', 'active',    'completed', 'CompleteProject'),
    ('project', 'on_hold',   'completed', 'CompleteProject'),
    ('project', 'active',    'cancelled', 'CancelProject'),
    ('project', 'on_hold',   'cancelled', 'CancelProject'),
    ('project', 'completed', 'active',    'ReopenProject'),
    ('project', 'cancelled', 'active',    'ReopenProject'),
    ('lease',   '',          'active',    'CreateLease'),
    ('lease',   'active',    'ended',     'EndLease');

CREATE TRIGGER projects_state_machine BEFORE INSERT OR UPDATE ON projects
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('project');
CREATE TRIGGER leases_state_machine BEFORE INSERT OR UPDATE ON leases
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('lease');

CREATE FUNCTION used_once() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.used_at IS NOT NULL AND NEW.used_at IS DISTINCT FROM OLD.used_at THEN
        RAISE EXCEPTION 'It was used already: that is never undone' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER user_recovery_codes_used_once BEFORE UPDATE ON user_recovery_codes
    FOR EACH ROW EXECUTE FUNCTION used_once();
CREATE TRIGGER user_email_tokens_used_once BEFORE UPDATE ON user_email_tokens
    FOR EACH ROW EXECUTE FUNCTION used_once();

CREATE FUNCTION users_mfa_step_forward() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.mfa_secret IS NOT DISTINCT FROM OLD.mfa_secret AND OLD.mfa_last_step IS NOT NULL
       AND (NEW.mfa_last_step IS NULL OR NEW.mfa_last_step < OLD.mfa_last_step) THEN
        RAISE EXCEPTION 'An authenticator code already accepted is not accepted again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER users_mfa_step_forward BEFORE UPDATE OF mfa_last_step, mfa_secret ON users
    FOR EACH ROW EXECUTE FUNCTION users_mfa_step_forward();

CREATE FUNCTION recurring_invoices_count_up() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.made_count < OLD.made_count THEN
        RAISE EXCEPTION 'The count of invoices made only goes up' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE FUNCTION recurring_journals_count_up() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.posted_count < OLD.posted_count THEN
        RAISE EXCEPTION 'The count of journals posted only goes up' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER recurring_invoices_counts_up BEFORE UPDATE ON recurring_invoices
    FOR EACH ROW EXECUTE FUNCTION recurring_invoices_count_up();
CREATE TRIGGER recurring_journals_counts_up BEFORE UPDATE ON recurring_journals
    FOR EACH ROW EXECUTE FUNCTION recurring_journals_count_up();

CREATE FUNCTION assembly_builds_value_once() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.value IS NOT NULL AND NEW.value IS DISTINCT FROM OLD.value THEN
        RAISE EXCEPTION 'A build''s value is set once' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER assembly_builds_value_once BEFORE UPDATE ON assembly_builds
    FOR EACH ROW EXECUTE FUNCTION assembly_builds_value_once();

REVOKE ALL ON FUNCTION used_once() FROM PUBLIC;
REVOKE ALL ON FUNCTION users_mfa_step_forward() FROM PUBLIC;
REVOKE ALL ON FUNCTION recurring_invoices_count_up() FROM PUBLIC;
REVOKE ALL ON FUNCTION recurring_journals_count_up() FROM PUBLIC;
REVOKE ALL ON FUNCTION assembly_builds_value_once() FROM PUBLIC;

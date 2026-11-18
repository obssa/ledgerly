-- Ledgerly core schema, part 53: Ledgerly's own subscriptions, without a payment provider (Module 11).
--
-- A company's organisation has a plan and a status (001, 006). Customers pay Ledgerly by bank transfer; the operator
-- records it (ledgerly:subscription, run as the owner role), which sets the subscription active until a date. Rules here:
--   1. Every change of plan, status or dates is kept in subscription_events, which the application cannot read or write.
--   2. Time moves a subscription only towards less: a trial past its end is suspended; an active one past its paid-until
--      date is past due; past due beyond the grace days is suspended. subscriptions_tick() applies that, only outside any
--      company, never to the shared demo; suspended and cancelled books are read-only (002, ResolveCompany).
--   3. Nothing here makes a subscription active: only the operator does, as the owner role.

ALTER TABLE organisations ADD COLUMN paid_until date;

CREATE TABLE subscription_events (
    id              bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organisation_id uuid        NOT NULL REFERENCES organisations (id),
    action          text        NOT NULL CHECK (action ~ '^[a-z_]{3,40}$'),
    from_status     text,
    to_status       text,
    plan            text,
    paid_until      date,
    trial_ends_at   timestamptz,
    reference       text        CHECK (length(reference) <= 120),
    note            text        CHECK (length(note) <= 500),
    by_whom         text        NOT NULL CHECK (length(by_whom) BETWEEN 1 AND 120),
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX subscription_events_org ON subscription_events (organisation_id, created_at);
ALTER TABLE subscription_events ENABLE ROW LEVEL SECURITY;   -- no policy: the application role has no access at all
REVOKE ALL ON subscription_events FROM {{app_role}};

-- The shared demo is never suspended: its organisation holds a demo company (018).
CREATE FUNCTION subscription_is_demo(p_organisation uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM demo_accounts d JOIN companies c ON c.id = d.company_id WHERE c.organisation_id = p_organisation)
$$;

CREATE FUNCTION subscriptions_tick(p_today date, p_grace_days integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    o record;
    moved integer := 0;
    next text;
BEGIN
    IF app_company_id() IS NOT NULL OR app_user_id() IS NOT NULL THEN
        RAISE EXCEPTION 'Subscriptions move with time only outside any company' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_grace_days < 0 OR p_grace_days > 90 THEN
        RAISE EXCEPTION 'Grace is 0 to 90 days' USING ERRCODE = 'check_violation';
    END IF;
    FOR o IN SELECT id, status, trial_ends_at, paid_until, past_due_since FROM organisations
              WHERE status IN ('trial', 'active', 'past_due') FOR UPDATE LOOP
        IF subscription_is_demo(o.id) THEN
            CONTINUE;
        END IF;
        next := CASE
            WHEN o.status = 'trial' AND o.trial_ends_at IS NOT NULL AND o.trial_ends_at::date < p_today THEN 'suspended'
            WHEN o.status = 'active' AND o.paid_until IS NOT NULL AND o.paid_until < p_today THEN 'past_due'
            WHEN o.status = 'past_due' AND coalesce(o.paid_until, o.past_due_since::date) + p_grace_days < p_today THEN 'suspended'
        END;
        IF next IS NULL THEN
            CONTINUE;
        END IF;
        UPDATE organisations SET status = next, updated_at = now(),
               past_due_since = CASE WHEN next = 'past_due' THEN now() ELSE past_due_since END
         WHERE id = o.id;
        INSERT INTO subscription_events (organisation_id, action, from_status, to_status, paid_until, by_whom)
            VALUES (o.id, CASE next WHEN 'past_due' THEN 'payment_overdue' ELSE CASE o.status WHEN 'trial' THEN 'trial_ended' ELSE 'suspended_for_non_payment' END END,
                    o.status, next, o.paid_until, 'scheduler');
        moved := moved + 1;
    END LOOP;
    RETURN moved;
END $$;
REVOKE ALL ON FUNCTION subscriptions_tick(date, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION subscriptions_tick(date, integer) TO {{app_role}};
REVOKE ALL ON FUNCTION subscription_is_demo(uuid) FROM PUBLIC;

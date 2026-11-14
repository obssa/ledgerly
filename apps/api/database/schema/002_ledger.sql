-- Ledgerly core schema, part 2: the general ledger.
-- Rules enforced here, in the database, so no code path can bypass them:
--   1. Every journal entry balances (sum of debits = sum of credits) and has at least two lines.
--   2. Posted entries and lines are immutable; corrections are reversing entries. Lines can only be
--      written in the transaction that creates their entry.
--   3. Nothing posts into a locked period, an archived company or a suspended subscription.
--      Posting and period closing cannot slip past each other (shared / exclusive lock).
--   4. A line's company and account always belong to the entry's company.
--   5. account_balances (per account and month) is kept in step with every posting, in the same
--      transaction, so the trial balance and statements never scan the ledger lines.
-- journal_entries and journal_lines are hash-partitioned by company: every key starts with company_id.

CREATE TABLE accounts (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    code        text        NOT NULL,
    name        text        NOT NULL,
    type        text        NOT NULL CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense')),
    subtype     text,       -- bank, cash, mobile_money, receivable, payable, tax_payable, inventory, cogs, retained_earnings ...
    system_key  text,       -- stable key the code looks up, e.g. 'accounts_receivable', 'vat_payable'
    parent_id   uuid        REFERENCES accounts (id),
    currency    char(3),    -- set for bank and wallet accounts held in a foreign currency
    is_active   boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, code),
    UNIQUE (company_id, system_key)
);

CREATE TABLE journal_entries (
    id          uuid          NOT NULL DEFAULT gen_random_uuid(),  -- the application sends time-ordered UUIDs (v7)
    company_id  uuid          NOT NULL REFERENCES companies (id),
    number      bigint        NOT NULL,
    entry_date  date          NOT NULL,
    memo        text,
    source_type text          NOT NULL DEFAULT 'manual', -- invoice, bill, payment, manual, reversal, opening, closing ...
    source_id   uuid,
    currency    char(3)       NOT NULL,                   -- transaction currency
    fx_rate     numeric(18,6) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
    status      text          NOT NULL DEFAULT 'posted' CHECK (status IN ('posted', 'reversed')),
    reverses_id uuid,
    posted_by   uuid          REFERENCES users (id),
    posted_at   timestamptz   NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, id),
    UNIQUE (company_id, number),
    FOREIGN KEY (company_id, reverses_id) REFERENCES journal_entries (company_id, id)
) PARTITION BY HASH (company_id);
SELECT create_hash_partitions('journal_entries', 16);
CREATE INDEX journal_entries_date_idx   ON journal_entries (company_id, entry_date);
CREATE INDEX journal_entries_source_idx ON journal_entries (company_id, source_type, source_id);

CREATE TABLE journal_lines (
    id               uuid          NOT NULL DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    journal_entry_id uuid          NOT NULL,
    line_no          integer       NOT NULL,
    entry_date       date          NOT NULL,  -- copied from the entry by trigger, so date-range reads stay inside one table
    account_id       uuid          NOT NULL REFERENCES accounts (id),
    description      text,
    debit            numeric(20,2) NOT NULL DEFAULT 0 CHECK (debit  >= 0), -- base currency
    credit           numeric(20,2) NOT NULL DEFAULT 0 CHECK (credit >= 0), -- base currency
    amount_txn       numeric(20,2) NOT NULL DEFAULT 0, -- signed (+debit / -credit) in the entry's currency
    contact_id       uuid,
    location_id      uuid,
    class_id         uuid,
    project_id       uuid,
    tags             jsonb         NOT NULL DEFAULT '{}'::jsonb,
    PRIMARY KEY (company_id, id),
    UNIQUE (company_id, journal_entry_id, line_no),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id) ON DELETE RESTRICT,
    CHECK (debit = 0 OR credit = 0),
    CHECK (debit + credit > 0)
) PARTITION BY HASH (company_id);
SELECT create_hash_partitions('journal_lines', 16);
CREATE INDEX journal_lines_account_idx ON journal_lines (company_id, account_id, entry_date);
CREATE INDEX journal_lines_contact_idx ON journal_lines (company_id, contact_id, entry_date) WHERE contact_id IS NOT NULL;

-- Running totals per account and calendar month. Written only by the trigger below (the application role
-- has SELECT only). ledger_verify() proves it equals the ledger lines.
CREATE TABLE account_balances (
    company_id  uuid          NOT NULL REFERENCES companies (id),
    account_id  uuid          NOT NULL REFERENCES accounts (id),
    period      date          NOT NULL CHECK (period = date_trunc('month', period)::date), -- first day of the month
    debit       numeric(20,2) NOT NULL DEFAULT 0,
    credit      numeric(20,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, account_id, period)
);

-- Domain events written in the same transaction as the posting; a worker relays them to
-- webhooks, search, analytics and (later) the AI layer.
CREATE TABLE outbox_events (
    id             bigserial PRIMARY KEY,
    company_id     uuid        NOT NULL REFERENCES companies (id),
    event_type     text        NOT NULL,
    aggregate_type text        NOT NULL,
    aggregate_id   uuid        NOT NULL,
    payload        jsonb       NOT NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    dispatched_at  timestamptz
);
CREATE INDEX outbox_pending_idx ON outbox_events (id) WHERE dispatched_at IS NULL;

-- 1. Balance check, deferred to commit so all lines of an entry are inserted first.
CREATE FUNCTION ledger_assert_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    total_debit  numeric;
    total_credit numeric;
    line_count   integer;
BEGIN
    SELECT coalesce(sum(debit), 0), coalesce(sum(credit), 0), count(*)
      INTO total_debit, total_credit, line_count
      FROM journal_lines WHERE company_id = NEW.company_id AND journal_entry_id = NEW.journal_entry_id;

    IF line_count < 2 THEN
        RAISE EXCEPTION 'Journal entry % needs at least two lines', NEW.journal_entry_id
            USING ERRCODE = 'check_violation';
    END IF;
    IF total_debit <> total_credit THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced: debits % <> credits %',
            NEW.journal_entry_id, total_debit, total_credit
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER journal_lines_balanced
    AFTER INSERT ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_balanced();

-- An entry with no lines at all would escape the line trigger, so check entries too.
CREATE FUNCTION ledger_assert_entry_has_lines() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM journal_lines WHERE company_id = NEW.company_id AND journal_entry_id = NEW.id) THEN
        RAISE EXCEPTION 'Journal entry % has no lines', NEW.id USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER journal_entries_have_lines
    AFTER INSERT ON journal_entries
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_entry_has_lines();

-- 2. Immutability. The only change allowed is marking an entry 'reversed' (done by the reversal posting).
CREATE FUNCTION ledger_block_line_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted journal lines are immutable; post a reversing entry instead'
        USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER journal_lines_immutable
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION ledger_block_line_changes();

CREATE FUNCTION ledger_guard_entry_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Posted journal entries cannot be deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF OLD.status = 'posted' AND NEW.status = 'reversed'
       AND (to_jsonb(NEW) - 'status') = (to_jsonb(OLD) - 'status') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Posted journal entries are immutable; post a reversing entry instead'
        USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER journal_entries_immutable
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION ledger_guard_entry_changes();

-- 3. Period lock, company and subscription state.
-- Posting takes a SHARED advisory lock on the company's period; changing books_closed_until takes the
-- EXCLUSIVE one. Many postings run side by side, but a close waits for postings in flight and postings
-- that arrive during a close wait and then see the new lock date.
CREATE FUNCTION ledger_period_lock_key(p_company_id uuid) RETURNS bigint LANGUAGE sql IMMUTABLE AS $$
    SELECT hashtextextended('ledger_period:' || p_company_id::text, 0)
$$;

-- SECURITY DEFINER: reads the organisation's status whatever the caller's row-level-security context.
CREATE FUNCTION ledger_assert_can_post() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}} AS $$
DECLARE
    closed_until   date;
    company_status text;
    org_status     text;
BEGIN
    PERFORM pg_advisory_xact_lock_shared(ledger_period_lock_key(NEW.company_id));

    SELECT c.books_closed_until, c.status, o.status INTO closed_until, company_status, org_status
      FROM companies c JOIN organisations o ON o.id = c.organisation_id
     WHERE c.id = NEW.company_id;

    IF org_status IN ('suspended', 'cancelled') THEN
        RAISE EXCEPTION 'The subscription is %; the books are read-only', org_status USING ERRCODE = 'check_violation';
    END IF;
    IF company_status = 'archived' THEN
        RAISE EXCEPTION 'This company is archived; the books are read-only' USING ERRCODE = 'check_violation';
    END IF;
    IF closed_until IS NOT NULL AND NEW.entry_date <= closed_until THEN
        RAISE EXCEPTION 'Books are closed until %; cannot post an entry dated %', closed_until, NEW.entry_date
            USING ERRCODE = 'check_violation';
    END IF;

    NEW.posted_at := now();  -- transaction start time; lines are accepted only in this same transaction
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_can_post
    BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_can_post();

CREATE FUNCTION companies_lock_period_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.books_closed_until IS DISTINCT FROM OLD.books_closed_until THEN
        PERFORM pg_advisory_xact_lock(ledger_period_lock_key(NEW.id));
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER companies_period_change
    BEFORE UPDATE ON companies
    FOR EACH ROW EXECUTE FUNCTION companies_lock_period_change();

-- 4. Same-company integrity for lines; lines join their entry only in the transaction that created it.
CREATE FUNCTION ledger_assert_line_company() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    entry record;
BEGIN
    SELECT entry_date, posted_at INTO entry
      FROM journal_entries e WHERE e.company_id = NEW.company_id AND e.id = NEW.journal_entry_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Line company does not match its journal entry' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF entry.posted_at <> now() THEN
        RAISE EXCEPTION 'Lines cannot be added to a journal entry that is already posted'
            USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM accounts a WHERE a.id = NEW.account_id AND a.company_id = NEW.company_id AND a.is_active) THEN
        RAISE EXCEPTION 'Account % is not an active account of this company', NEW.account_id
            USING ERRCODE = 'foreign_key_violation';
    END IF;
    NEW.entry_date := entry.entry_date;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_same_company
    BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_line_company();

-- 5. Keep account_balances in step. One statement-level trigger per INSERT, rows applied in a fixed
-- (account, period) order so two postings can never deadlock on the balance rows.
CREATE FUNCTION ledger_apply_balances() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}} AS $$
BEGIN
    INSERT INTO account_balances (company_id, account_id, period, debit, credit)
    SELECT company_id, account_id, date_trunc('month', entry_date)::date, sum(debit), sum(credit)
      FROM new_lines
     GROUP BY 1, 2, 3
     ORDER BY 1, 2, 3
    ON CONFLICT (company_id, account_id, period)
    DO UPDATE SET debit  = account_balances.debit  + EXCLUDED.debit,
                  credit = account_balances.credit + EXCLUDED.credit;
    RETURN NULL;
END $$;

CREATE TRIGGER journal_lines_apply_balances
    AFTER INSERT ON journal_lines
    REFERENCING NEW TABLE AS new_lines
    FOR EACH STATEMENT EXECUTE FUNCTION ledger_apply_balances();

-- Trial balance as at any date, for any calendar: whole months come from account_balances, only the days
-- of a part month are read from the lines (one index range per account).
CREATE FUNCTION trial_balance(p_company_id uuid, p_as_at date)
RETURNS TABLE (account_id uuid, debit numeric, credit numeric) LANGUAGE sql STABLE AS $$
    WITH bounds AS (
        SELECT CASE WHEN p_as_at = (date_trunc('month', p_as_at) + interval '1 month - 1 day')::date
                    THEN (date_trunc('month', p_as_at) + interval '1 month')::date
                    ELSE date_trunc('month', p_as_at)::date END AS whole_before
    ), parts AS (
        SELECT b.account_id, b.debit, b.credit
          FROM account_balances b, bounds
         WHERE b.company_id = p_company_id AND b.period < bounds.whole_before
        UNION ALL
        SELECT l.account_id, l.debit, l.credit
          FROM journal_lines l, bounds
         WHERE l.company_id = p_company_id AND l.entry_date >= bounds.whole_before AND l.entry_date <= p_as_at
    )
    SELECT account_id, sum(debit), sum(credit) FROM parts GROUP BY account_id
$$;

-- Ledger invariants for one company; run nightly for every company. Returns one row per problem, none when sound.
CREATE FUNCTION ledger_verify(p_company_id uuid)
RETURNS TABLE (problem text, detail text) LANGUAGE sql STABLE AS $$
    WITH lines AS (
        SELECT account_id, date_trunc('month', entry_date)::date AS period, sum(debit) AS debit, sum(credit) AS credit
          FROM journal_lines WHERE company_id = p_company_id GROUP BY 1, 2
    ), bal AS (
        SELECT account_id, period, debit, credit FROM account_balances WHERE company_id = p_company_id
    )
    SELECT 'ledger does not balance', 'debits ' || sum(debit) || ' <> credits ' || sum(credit)
      FROM lines HAVING sum(debit) <> sum(credit)
    UNION ALL
    SELECT 'balances differ from ledger lines', 'account ' || coalesce(l.account_id, b.account_id) || ' period ' || coalesce(l.period, b.period)
      FROM lines l FULL JOIN bal b USING (account_id, period)
     WHERE (l.debit, l.credit) IS DISTINCT FROM (b.debit, b.credit)
    UNION ALL
    SELECT 'entry does not balance', 'entry ' || journal_entry_id
      FROM journal_lines WHERE company_id = p_company_id
     GROUP BY journal_entry_id HAVING sum(debit) <> sum(credit)
$$;

-- Ledgerly core schema, part 2: the general ledger.
-- Rules enforced here, in the database, so no code path can bypass them:
--   1. Every journal entry balances (sum of debits = sum of credits) and has at least two lines.
--   2. Posted entries and lines are immutable; corrections are reversing entries.
--   3. Nothing posts into a locked period (companies.books_closed_until).
--   4. A line's company and account always belong to the entry's company.

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
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    number      bigint        NOT NULL,
    entry_date  date          NOT NULL,
    memo        text,
    source_type text          NOT NULL DEFAULT 'manual', -- invoice, bill, payment, payroll_run, manual, reversal ...
    source_id   uuid,
    currency    char(3)       NOT NULL,                   -- transaction currency
    fx_rate     numeric(18,8) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
    status      text          NOT NULL DEFAULT 'posted' CHECK (status IN ('posted', 'reversed')),
    reverses_id uuid          REFERENCES journal_entries (id),
    posted_by   uuid          REFERENCES users (id),
    posted_at   timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, number)
);
CREATE INDEX journal_entries_date_idx   ON journal_entries (company_id, entry_date);
CREATE INDEX journal_entries_source_idx ON journal_entries (company_id, source_type, source_id);

CREATE TABLE journal_lines (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    journal_entry_id uuid          NOT NULL REFERENCES journal_entries (id) ON DELETE RESTRICT,
    line_no          integer       NOT NULL,
    account_id       uuid          NOT NULL REFERENCES accounts (id),
    description      text,
    debit            numeric(20,4) NOT NULL DEFAULT 0 CHECK (debit  >= 0), -- base currency
    credit           numeric(20,4) NOT NULL DEFAULT 0 CHECK (credit >= 0), -- base currency
    amount_txn       numeric(20,4) NOT NULL DEFAULT 0, -- signed (+debit / -credit) in the entry's currency
    contact_id       uuid,
    location_id      uuid,
    class_id         uuid,
    project_id       uuid,
    tags             jsonb         NOT NULL DEFAULT '{}'::jsonb,
    CHECK (debit = 0 OR credit = 0),
    CHECK (debit + credit > 0),
    UNIQUE (journal_entry_id, line_no)
);
CREATE INDEX journal_lines_account_idx ON journal_lines (company_id, account_id);

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
      FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;

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
    IF NOT EXISTS (SELECT 1 FROM journal_lines WHERE journal_entry_id = NEW.id) THEN
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
       AND (NEW.company_id, NEW.number, NEW.entry_date, NEW.currency, NEW.fx_rate, NEW.source_type)
           IS NOT DISTINCT FROM (OLD.company_id, OLD.number, OLD.entry_date, OLD.currency, OLD.fx_rate, OLD.source_type)
       AND NEW.source_id IS NOT DISTINCT FROM OLD.source_id
       AND NEW.memo IS NOT DISTINCT FROM OLD.memo THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Posted journal entries are immutable; post a reversing entry instead'
        USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER journal_entries_immutable
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION ledger_guard_entry_changes();

-- 3. Period lock.
CREATE FUNCTION ledger_assert_period_open() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    closed_until date;
BEGIN
    SELECT books_closed_until INTO closed_until FROM companies WHERE id = NEW.company_id;
    IF closed_until IS NOT NULL AND NEW.entry_date <= closed_until THEN
        RAISE EXCEPTION 'Books are closed until %; cannot post an entry dated %', closed_until, NEW.entry_date
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_period_open
    BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_period_open();

-- 4. Same-company integrity for lines.
CREATE FUNCTION ledger_assert_line_company() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM journal_entries e WHERE e.id = NEW.journal_entry_id AND e.company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'Line company does not match its journal entry' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM accounts a WHERE a.id = NEW.account_id AND a.company_id = NEW.company_id AND a.is_active) THEN
        RAISE EXCEPTION 'Account % is not an active account of this company', NEW.account_id
            USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_same_company
    BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION ledger_assert_line_company();

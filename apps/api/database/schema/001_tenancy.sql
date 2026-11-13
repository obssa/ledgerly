-- Ledgerly core schema, part 1: tenancy and identity.
-- An organisation is the paying tenant; it owns one or more companies (legal entities with their own books).
-- Every business table carries company_id and is protected by row-level security (005_rls.sql).
-- The big append-only tables are hash-partitioned by company_id from this first migration.

-- Creates p_count hash partitions of a table partitioned BY HASH (company_id).
-- 16 here; choose the production count (64 is a sensible default) before the first customer: it is costly to change later.
CREATE FUNCTION create_hash_partitions(p_table regclass, p_count integer) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    FOR i IN 0 .. p_count - 1 LOOP
        EXECUTE format('CREATE TABLE %I PARTITION OF %s FOR VALUES WITH (MODULUS %s, REMAINDER %s)',
                       p_table::text || '_p' || lpad(i::text, 2, '0'), p_table, p_count, i);
    END LOOP;
END $$;

CREATE TABLE users (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name              text        NOT NULL,
    email             text        NOT NULL UNIQUE,
    phone             text,
    password          text        NOT NULL,
    locale            text        NOT NULL DEFAULT 'en',
    mfa_secret        text,
    email_verified_at timestamptz,
    remember_token    text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

-- Subscription state machine (007_state_machines.sql): trial -> active -> past_due -> suspended -> cancelled.
-- suspended and cancelled are read-only: the ledger refuses new postings (002_ledger.sql).
-- The application role may only change the name; billing changes status through the owner role.
CREATE TABLE organisations (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name           text        NOT NULL,
    plan           text        NOT NULL DEFAULT 'starter'
                   CHECK (plan IN ('starter', 'essentials', 'plus', 'advanced', 'accountant')),
    status         text        NOT NULL DEFAULT 'trial'
                   CHECK (status IN ('trial', 'active', 'past_due', 'suspended', 'cancelled')),
    trial_ends_at  timestamptz,
    past_due_since timestamptz,
    cancelled_at   timestamptz,
    delete_after   date,        -- set on cancel: end of the statutory retention period for the books
    created_by     uuid        REFERENCES users (id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE companies (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organisation_id         uuid        NOT NULL REFERENCES organisations (id),
    legal_name              text        NOT NULL,
    trading_name            text,
    country_code            char(2)     NOT NULL,                 -- any ISO 3166-1 country
    base_currency           char(3)     NOT NULL,                 -- any ISO 4217 currency; amounts always have 2 decimals
    timezone                text        NOT NULL DEFAULT 'UTC',   -- IANA name, e.g. Africa/Addis_Ababa
    fiscal_year_start_month smallint    NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    calendar                text        NOT NULL DEFAULT 'gregorian' CHECK (calendar IN ('gregorian', 'ethiopian')),
    accounting_basis        text        NOT NULL DEFAULT 'accrual' CHECK (accounting_basis IN ('accrual', 'cash')),
    industry                text,
    tin                     text,
    vat_registered          boolean     NOT NULL DEFAULT false,
    vat_number              text,
    phone                   text,
    email                   text,
    website                 text,
    languages               text[]      NOT NULL DEFAULT ARRAY['en'],
    framework               text        NOT NULL DEFAULT 'ifrs_sme' CHECK (framework IN ('ifrs_sme')),
    status                  text        NOT NULL DEFAULT 'setting_up' CHECK (status IN ('setting_up', 'live', 'archived')),
    conversion_date         date,       -- last bank statement date before Ledgerly; opening balances are as at this date
    books_closed_until      date,       -- period lock: nothing may post on or before this date
    settings                jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX companies_organisation_idx ON companies (organisation_id);

CREATE TABLE memberships (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    user_id     uuid        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role        text        NOT NULL,           -- owner, admin, accountant, bookkeeper, sales, purchasing, approver, viewer, custom
    permissions jsonb       NOT NULL DEFAULT '[]'::jsonb, -- extra grants for the 'custom' role
    status      text        NOT NULL DEFAULT 'active' CHECK (status IN ('invited', 'active', 'deactivated')),
    invited_at  timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, user_id)
);
CREATE INDEX memberships_user_idx ON memberships (user_id);

-- Fiscal years. The enforcing lock is companies.books_closed_until; this table records where each year is
-- in its state machine: open -> closing -> closed -> reopened -> closed.
CREATE TABLE fiscal_years (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    starts_on   date        NOT NULL,
    ends_on     date        NOT NULL,
    status      text        NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closing', 'closed', 'reopened')),
    closed_by   uuid        REFERENCES users (id),
    closed_at   timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, ends_on),
    CHECK (ends_on > starts_on)
);

-- Per-company gapless counters (journal numbers, invoice numbers). The row is locked FOR UPDATE for the
-- instant of posting; this is the only lock that serialises postings of one company, and it never blocks
-- another company or ordinary reads and writes.
CREATE TABLE company_sequences (
    company_id  uuid   NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    key         text   NOT NULL,
    next_value  bigint NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, key)
);

-- A create or post request carries an Idempotency-Key header. The first request stores its response here;
-- a repeat (double click, network retry) gets the stored response back and nothing happens twice.
CREATE TABLE idempotency_keys (
    company_id   uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    key          text        NOT NULL CHECK (length(key) BETWEEN 8 AND 200),
    user_id      uuid        REFERENCES users (id),
    method       text        NOT NULL,
    path         text        NOT NULL,
    request_hash text        NOT NULL,   -- sha256 of the body: the same key with a different body is refused
    status_code  integer,                -- NULL while the first request is still running
    response     jsonb,
    created_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, key)
);
CREATE INDEX idempotency_keys_age_idx ON idempotency_keys (created_at);  -- a daily job deletes keys older than 48 hours

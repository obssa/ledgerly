-- Ledgerly core schema, part 1: tenancy and identity.
-- An organisation is the paying tenant; it owns one or more companies (legal entities with their own books).
-- Every business table below carries company_id and is protected by row-level security (005_rls.sql).

CREATE TABLE organisations (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text        NOT NULL,
    plan        text        NOT NULL DEFAULT 'starter'
                CHECK (plan IN ('starter', 'essentials', 'plus', 'advanced', 'accountant')),
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

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

CREATE TABLE companies (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    organisation_id         uuid        NOT NULL REFERENCES organisations (id),
    legal_name              text        NOT NULL,
    trading_name            text,
    country_code            char(2)     NOT NULL,
    base_currency           char(3)     NOT NULL,
    fiscal_year_start_month smallint    NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    calendar                text        NOT NULL DEFAULT 'gregorian' CHECK (calendar IN ('gregorian', 'ethiopian')),
    accounting_basis        text        NOT NULL DEFAULT 'accrual' CHECK (accounting_basis IN ('accrual', 'cash')),
    industry                text,
    tin                     text,
    vat_registered          boolean     NOT NULL DEFAULT false,
    vat_number              text,
    languages               text[]      NOT NULL DEFAULT ARRAY['en'],
    framework               text        NOT NULL DEFAULT 'ifrs_sme' CHECK (framework IN ('ifrs_sme', 'syscohada')),
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
    role        text        NOT NULL,           -- owner, admin, accountant, bookkeeper, sales, purchasing, payroll, approver, viewer, custom
    permissions jsonb       NOT NULL DEFAULT '[]'::jsonb, -- extra grants for the 'custom' role
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, user_id)
);
CREATE INDEX memberships_user_idx ON memberships (user_id);

-- Per-company gapless counters (journal numbers, invoice numbers). Rows are locked with SELECT ... FOR UPDATE.
CREATE TABLE company_sequences (
    company_id  uuid   NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    key         text   NOT NULL,
    next_value  bigint NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, key)
);

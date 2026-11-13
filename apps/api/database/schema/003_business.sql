-- Ledgerly core schema, part 3: contacts, tax rates, business documents and payments.
-- Documents (quotes, invoices, bills, credit notes) are editable until posted; posting creates a
-- journal entry and stores its id on the document.

CREATE TABLE contacts (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id         uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    name               text        NOT NULL,
    is_customer        boolean     NOT NULL DEFAULT false,
    is_vendor          boolean     NOT NULL DEFAULT false,
    tax_id             text,        -- TIN / KRA PIN / NIN, validated per country
    email              text,
    phone              text,
    currency           char(3),
    payment_terms_days integer     NOT NULL DEFAULT 30 CHECK (payment_terms_days >= 0),
    address            jsonb       NOT NULL DEFAULT '{}'::jsonb,
    custom_fields      jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (is_customer OR is_vendor)
);
CREATE INDEX contacts_name_idx ON contacts (company_id, lower(name));

-- Tax rates are versioned rules: a change in law adds a row with a new effective_from; old rows are never edited,
-- so documents in earlier periods still recalculate correctly. company_id NULL = shipped by a country pack.
CREATE TABLE tax_rates (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id     uuid         REFERENCES companies (id) ON DELETE CASCADE,
    country_code   char(2)      NOT NULL,
    code           text         NOT NULL,    -- VAT15, VAT0, EXEMPT, TOT2, WHT3 ...
    name           text         NOT NULL,
    kind           text         NOT NULL CHECK (kind IN ('vat', 'tot', 'withholding', 'excise', 'other')),
    rate           numeric(9,6) NOT NULL CHECK (rate >= 0 AND rate < 1), -- 0.15 = 15%
    effective_from date         NOT NULL,
    effective_to   date,
    authority_code text,         -- code the tax authority expects in e-invoices
    created_at     timestamptz  NOT NULL DEFAULT now(),
    CHECK (effective_to IS NULL OR effective_to > effective_from)
);
CREATE UNIQUE INDEX tax_rates_unique_version
    ON tax_rates (coalesce(company_id, '00000000-0000-0000-0000-000000000000'::uuid), country_code, code, effective_from);

CREATE TABLE items (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    sku               text,
    name              text          NOT NULL,
    type              text          NOT NULL CHECK (type IN ('inventory', 'non_inventory', 'service', 'bundle')),
    unit              text          NOT NULL DEFAULT 'each',
    sale_price        numeric(20,2),
    purchase_price    numeric(20,2),
    income_account_id uuid          REFERENCES accounts (id),
    expense_account_id uuid         REFERENCES accounts (id),
    default_tax_code  text,
    authority_item_code text,       -- item classification code required by some tax authorities
    is_active         boolean       NOT NULL DEFAULT true,
    created_at        timestamptz   NOT NULL DEFAULT now(),
    updated_at        timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, sku)
);

CREATE TABLE warehouses (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid        NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    name        text        NOT NULL,
    is_active   boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, name)
);

-- Every stock movement, append-only. The bin card (stock card) is this table read in order per item and
-- warehouse, with a running quantity and value. quantity is signed: + in (purchase, return in, count gain),
-- - out (sale, issue, count loss). A transfer is two rows sharing transfer_group.
CREATE TABLE stock_moves (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id     uuid          NOT NULL REFERENCES companies (id),
    item_id        uuid          NOT NULL REFERENCES items (id),
    warehouse_id   uuid          NOT NULL REFERENCES warehouses (id),
    moved_on       date          NOT NULL,
    kind           text          NOT NULL CHECK (kind IN ('opening', 'purchase', 'sale', 'sale_return', 'purchase_return',
                                                          'transfer_in', 'transfer_out', 'adjustment', 'production')),
    quantity       numeric(18,2) NOT NULL CHECK (quantity <> 0),
    unit_cost      numeric(20,2) NOT NULL CHECK (unit_cost >= 0), -- weighted-average or FIFO layer cost at the time
    reference      text,          -- invoice / bill / GRN / count sheet number shown on the bin card
    document_id    uuid,
    transfer_group uuid,
    created_by     uuid          REFERENCES users (id),
    created_at     timestamptz   NOT NULL DEFAULT now(),
    seq            bigserial     -- stable order for moves on the same day
);
CREATE INDEX stock_moves_card_idx ON stock_moves (company_id, item_id, warehouse_id, moved_on, seq);

CREATE FUNCTION stock_moves_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Stock moves are append-only; record an adjustment instead' USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER stock_moves_no_changes
    BEFORE UPDATE OR DELETE ON stock_moves
    FOR EACH ROW EXECUTE FUNCTION stock_moves_append_only();

CREATE TABLE documents (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    type             text          NOT NULL CHECK (type IN ('quote', 'sales_order', 'invoice', 'credit_note',
                                                          'purchase_order', 'bill', 'vendor_credit', 'sales_receipt')),
    number           text          NOT NULL,
    contact_id       uuid          NOT NULL REFERENCES contacts (id),
    issue_date       date          NOT NULL,
    due_date         date,
    currency         char(3)       NOT NULL,
    fx_rate          numeric(18,6) NOT NULL DEFAULT 1 CHECK (fx_rate > 0),
    prices_include_tax boolean     NOT NULL DEFAULT false,
    status           text          NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'posted', 'void')),
    subtotal         numeric(20,2) NOT NULL DEFAULT 0,
    tax_total        numeric(20,2) NOT NULL DEFAULT 0,
    total            numeric(20,2) NOT NULL DEFAULT 0,
    amount_due       numeric(20,2) NOT NULL DEFAULT 0,
    language         text          NOT NULL DEFAULT 'en',
    notes            text,
    journal_entry_id uuid          REFERENCES journal_entries (id),
    location_id      uuid,
    custom_fields    jsonb         NOT NULL DEFAULT '{}'::jsonb,
    created_by       uuid          REFERENCES users (id),
    created_at       timestamptz   NOT NULL DEFAULT now(),
    updated_at       timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, type, number),
    CHECK (status <> 'posted' OR journal_entry_id IS NOT NULL)
);
CREATE INDEX documents_contact_idx ON documents (company_id, contact_id);
CREATE INDEX documents_open_idx    ON documents (company_id, type, due_date) WHERE amount_due > 0;

CREATE TABLE document_lines (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    uuid          NOT NULL REFERENCES companies (id),
    document_id   uuid          NOT NULL REFERENCES documents (id) ON DELETE CASCADE,
    line_no       integer       NOT NULL,
    item_id       uuid          REFERENCES items (id),
    description   text          NOT NULL,
    quantity      numeric(18,2) NOT NULL CHECK (quantity <> 0),
    unit_price    numeric(20,2) NOT NULL,
    discount_rate numeric(9,6)  NOT NULL DEFAULT 0 CHECK (discount_rate >= 0 AND discount_rate <= 1),
    tax_rate_id   uuid          REFERENCES tax_rates (id),
    account_id    uuid          NOT NULL REFERENCES accounts (id),
    line_subtotal numeric(20,2) NOT NULL,
    line_tax      numeric(20,2) NOT NULL DEFAULT 0,
    UNIQUE (document_id, line_no)
);

-- Posted documents are frozen: only status (to 'void'), amount_due (as payments are allocated) and
-- updated_at may change.
CREATE FUNCTION documents_guard_posted() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IN ('posted', 'void') THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'Posted documents cannot be deleted; void them instead' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF (to_jsonb(NEW) - 'status' - 'amount_due' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'amount_due' - 'updated_at') THEN
            RAISE EXCEPTION 'Posted documents are frozen' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

CREATE TRIGGER documents_frozen_when_posted
    BEFORE UPDATE OR DELETE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_guard_posted();

CREATE TABLE payments (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    direction         text          NOT NULL CHECK (direction IN ('in', 'out')),
    contact_id        uuid          REFERENCES contacts (id),
    account_id        uuid          NOT NULL REFERENCES accounts (id), -- bank, cash or wallet account
    payment_date      date          NOT NULL,
    method            text          NOT NULL, -- bank_transfer, cash, cheque, card, telebirr, mpesa, mtn_momo, airtel_money ...
    reference         text,
    currency          char(3)       NOT NULL,
    fx_rate           numeric(18,6) NOT NULL DEFAULT 1,
    amount            numeric(20,2) NOT NULL CHECK (amount > 0),
    fee               numeric(20,2) NOT NULL DEFAULT 0 CHECK (fee >= 0),
    withholding       numeric(20,2) NOT NULL DEFAULT 0 CHECK (withholding >= 0),
    journal_entry_id  uuid          REFERENCES journal_entries (id),
    provider_txn_id   text,          -- id from the bank or mobile-money provider, for idempotency
    created_at        timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, method, provider_txn_id)
);

CREATE TABLE payment_allocations (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    payment_id  uuid          NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    document_id uuid          NOT NULL REFERENCES documents (id),
    amount      numeric(20,2) NOT NULL CHECK (amount > 0),
    UNIQUE (payment_id, document_id)
);

CREATE TABLE bank_transactions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    account_id       uuid          NOT NULL REFERENCES accounts (id),
    posted_on        date          NOT NULL,
    description      text          NOT NULL,
    amount           numeric(20,2) NOT NULL, -- + money in, - money out
    balance_after    numeric(20,2),
    source           text          NOT NULL CHECK (source IN ('feed', 'statement', 'manual', 'mobile_money_api')),
    external_id      text,
    status           text          NOT NULL DEFAULT 'for_review' CHECK (status IN ('for_review', 'matched', 'categorised', 'excluded')),
    journal_line_id  uuid          REFERENCES journal_lines (id),
    suggestion       jsonb,         -- best match or category with a confidence score
    created_at       timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (account_id, external_id)
);
CREATE INDEX bank_transactions_review_idx ON bank_transactions (company_id, account_id, status);

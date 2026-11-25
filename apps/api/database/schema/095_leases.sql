-- Evenbooks core schema, part 95: leases, as the lessee (Module 9, IFRS for SMEs section 20).
--
-- A lease is a number of equal monthly payments, in arrears (at each month's end) or in advance (at its start).
--   A finance lease (the risks and rewards of the asset pass to the company) is recognised at commencement at the
--     present value of its payments at its rate: Dr the asset (registered as a fixed asset at that cost, depreciated
--     as any other), Cr the lease liability, in an entry of its own (source lease, the lease's id). Each payment is
--     split by the effective interest method: the interest on what is owed since the last payment (none on a first
--     payment in advance), and the rest off the liability; the last payment takes off what is left.
--   An operating lease is an expense as each payment is made.
-- Rules:
--   1. A lease is the company's, with its lessor, accounts and asset the company's; a finance lease has its rate,
--      present value, liability and interest accounts, asset and commencement entry, an operating lease its expense
--      account. Once a payment is made only its name and whether it has ended change.
--   2. Payments are made in order, one to the term, each of the lease's amount, on or after it starts, of an active
--      lease; a finance payment's interest is the books' to the cent (round(owed * rate / 12, 2)), its principal the
--      rest, and the last one clears what is owed; an operating payment has no interest or principal.
--   3. A payment names its own entry and is kept.

CREATE TABLE leases (
    id                     uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id             uuid          NOT NULL REFERENCES companies (id),
    code                   text          NOT NULL CHECK (code ~ '^[A-Za-z0-9._/-]{1,30}$'),
    name                   text          NOT NULL CHECK (length(btrim(name)) BETWEEN 2 AND 120),
    lessor_id              uuid          REFERENCES contacts (id),
    kind                   text          NOT NULL CHECK (kind IN ('finance', 'operating')),
    starts_on              date          NOT NULL,
    months                 integer       NOT NULL CHECK (months BETWEEN 1 AND 600),
    payment                numeric(20,2) NOT NULL CHECK (payment > 0),
    in_advance             boolean       NOT NULL DEFAULT false,
    annual_rate            numeric(9,6)  CHECK (annual_rate >= 0 AND annual_rate < 1),
    present_value          numeric(20,2) CHECK (present_value > 0),
    liability_account_id   uuid          REFERENCES accounts (id),
    interest_account_id    uuid          REFERENCES accounts (id),
    expense_account_id     uuid          REFERENCES accounts (id),
    fixed_asset_id         uuid          REFERENCES fixed_assets (id),
    commencement_entry_id  uuid,
    status                 text          NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'ended')),
    created_by             uuid          NOT NULL REFERENCES users (id),
    created_at             timestamptz   NOT NULL DEFAULT now(),
    updated_at             timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, code),
    FOREIGN KEY (company_id, commencement_entry_id) REFERENCES journal_entries (company_id, id),
    CHECK ((kind = 'finance') = (annual_rate IS NOT NULL AND present_value IS NOT NULL AND liability_account_id IS NOT NULL
                                 AND interest_account_id IS NOT NULL AND fixed_asset_id IS NOT NULL AND commencement_entry_id IS NOT NULL)),
    CHECK ((kind = 'operating') = (expense_account_id IS NOT NULL)),
    CHECK (kind = 'finance' OR (annual_rate IS NULL AND present_value IS NULL AND liability_account_id IS NULL AND interest_account_id IS NULL
                                AND fixed_asset_id IS NULL AND commencement_entry_id IS NULL))
);

CREATE TABLE lease_payments (
    id          uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id  uuid          NOT NULL REFERENCES companies (id),
    lease_id    uuid          NOT NULL REFERENCES leases (id),
    number      integer       NOT NULL CHECK (number >= 1),
    paid_on     date          NOT NULL,
    amount      numeric(20,2) NOT NULL CHECK (amount > 0),
    interest    numeric(20,2) NOT NULL CHECK (interest >= 0),
    principal   numeric(20,2) NOT NULL CHECK (principal >= 0),
    entry_id    uuid          NOT NULL,
    created_by  uuid          NOT NULL REFERENCES users (id),
    created_at  timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (lease_id, number),
    FOREIGN KEY (company_id, entry_id) REFERENCES journal_entries (company_id, id)
);
CREATE INDEX lease_payments_lease ON lease_payments (company_id, lease_id);

CREATE FUNCTION leases_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A lease is ended, never deleted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.id, NEW.company_id, NEW.created_by) IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.created_by) THEN
            RAISE EXCEPTION 'A lease keeps its company and who set it up' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM lease_payments WHERE lease_id = OLD.id)
           AND (NEW.code, NEW.lessor_id, NEW.kind, NEW.starts_on, NEW.months, NEW.payment, NEW.in_advance, NEW.annual_rate, NEW.present_value,
                NEW.liability_account_id, NEW.interest_account_id, NEW.expense_account_id, NEW.fixed_asset_id, NEW.commencement_entry_id)
               IS DISTINCT FROM (OLD.code, OLD.lessor_id, OLD.kind, OLD.starts_on, OLD.months, OLD.payment, OLD.in_advance, OLD.annual_rate, OLD.present_value,
                OLD.liability_account_id, OLD.interest_account_id, OLD.expense_account_id, OLD.fixed_asset_id, OLD.commencement_entry_id) THEN
            RAISE EXCEPTION 'Lease % has payments: only its name and whether it has ended change', OLD.code USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NEW.lessor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.lessor_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A lease''s lessor is a contact of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF (NEW.kind = 'finance' AND NOT (
            EXISTS (SELECT 1 FROM accounts WHERE id = NEW.liability_account_id AND company_id = NEW.company_id AND type = 'liability')
        AND EXISTS (SELECT 1 FROM accounts WHERE id = NEW.interest_account_id AND company_id = NEW.company_id AND type = 'expense')
        AND EXISTS (SELECT 1 FROM fixed_assets WHERE id = NEW.fixed_asset_id AND company_id = NEW.company_id)
        AND EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.commencement_entry_id AND source_type = 'lease' AND source_id = NEW.id)))
       OR (NEW.kind = 'operating' AND NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.expense_account_id AND company_id = NEW.company_id AND type = 'expense')) THEN
        RAISE EXCEPTION 'A lease uses the company''s accounts (a liability and an interest expense, or an expense), its own asset and its own commencement entry'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION leases_valid() FROM PUBLIC;
CREATE TRIGGER leases_valid BEFORE INSERT OR UPDATE OR DELETE ON leases FOR EACH ROW EXECUTE FUNCTION leases_valid();

CREATE FUNCTION lease_payments_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    l record;
    made integer;
    owed numeric;
    expected numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A lease payment is kept as it was posted' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT * INTO l FROM leases WHERE id = NEW.lease_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR l.status <> 'active' OR NEW.paid_on < l.starts_on THEN
        RAISE EXCEPTION 'A lease payment is of an active lease of the company, on or after it starts' USING ERRCODE = 'check_violation';
    END IF;
    SELECT count(*), coalesce(l.present_value, 0) - coalesce(sum(principal), 0) INTO made, owed FROM lease_payments WHERE lease_id = l.id;
    IF NEW.number <> made + 1 OR NEW.number > l.months OR NEW.amount <> l.payment THEN
        RAISE EXCEPTION 'Lease % is paid in order, % of its amount, % times', l.code, l.payment, l.months USING ERRCODE = 'check_violation';
    END IF;
    IF l.kind = 'operating' THEN
        IF NEW.interest <> 0 OR NEW.principal <> 0 THEN
            RAISE EXCEPTION 'An operating lease payment is an expense: no interest or principal' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        expected := CASE WHEN NEW.number = l.months THEN NEW.amount - owed
                         WHEN NEW.number = 1 AND l.in_advance THEN 0
                         ELSE round(owed * l.annual_rate / 12, 2) END;
        IF NEW.interest <> expected OR NEW.interest + NEW.principal <> NEW.amount OR NEW.principal > owed
           OR (NEW.number = l.months AND NEW.principal <> owed) THEN
            RAISE EXCEPTION 'Payment % of lease % is % interest on the % owed, the rest off the liability', NEW.number, l.code, expected, owed
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.entry_id AND source_type = 'lease_payment' AND source_id = NEW.id) THEN
        RAISE EXCEPTION 'A lease payment names its own entry' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION lease_payments_valid() FROM PUBLIC;
CREATE TRIGGER lease_payments_valid BEFORE INSERT OR UPDATE OR DELETE ON lease_payments FOR EACH ROW EXECUTE FUNCTION lease_payments_valid();

ALTER TABLE leases ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON leases
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
ALTER TABLE lease_payments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON lease_payments
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON leases, lease_payments FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON leases TO {{app_role}};
GRANT SELECT, INSERT ON lease_payments TO {{app_role}};

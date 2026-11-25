-- Evenbooks core schema, part 39: tax returns (Module 7).
--
-- A tax return is the record of what was filed with the tax authority for a period: the figures as built from the
-- ledger when it was filed, the date and the authority's reference. Settling it posts one entry (source
-- 'tax_settlement'): VAT payable against VAT receivable and the bank for what is due (section 13). Rules here:
--   1. A return is filed by the person signed in, for a period that has ended, and periods of one kind of tax never
--      overlap. Its figures add up (net = output - input) and never change; only its settlement is added, once.
--   2. A settlement entry names its return, belongs to its company, and is dated after the period.
--   3. A filed period is locked for that tax: no entry may post to its tax accounts (VAT payable and receivable for a
--      VAT return; withholding payable and receivable for a withholding return) dated inside it. A correction goes into
--      a later period, as the authority expects.

CREATE TABLE tax_returns (
    id                  uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id          uuid          NOT NULL REFERENCES companies (id),
    kind                text          NOT NULL CHECK (kind IN ('vat', 'withholding')),
    period_start        date          NOT NULL,
    period_end          date          NOT NULL,
    filed_on            date          NOT NULL,
    reference           text          CHECK (length(reference) BETWEEN 1 AND 100),
    output_tax          numeric(20,2) NOT NULL,
    input_tax           numeric(20,2) NOT NULL,
    net                 numeric(20,2) NOT NULL,
    figures             jsonb         NOT NULL CHECK (jsonb_typeof(figures) = 'object'),
    filed_by            uuid          NOT NULL REFERENCES users (id),
    created_at          timestamptz   NOT NULL DEFAULT now(),
    settlement_entry_id uuid,
    settled_on          date,
    CHECK (period_end >= period_start AND period_end - period_start <= 366),
    CHECK (filed_on > period_end),
    CHECK (net = output_tax - input_tax),
    CHECK ((settlement_entry_id IS NULL) = (settled_on IS NULL)),
    CHECK (settled_on IS NULL OR settled_on > period_end),
    FOREIGN KEY (company_id, settlement_entry_id) REFERENCES journal_entries (company_id, id)
);
CREATE INDEX tax_returns_period_idx ON tax_returns (company_id, kind, period_start);

CREATE FUNCTION tax_returns_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF app_user_id() IS NOT NULL AND NEW.filed_by IS DISTINCT FROM app_user_id() THEN
            RAISE EXCEPTION 'A return is filed by the person signed in' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM tax_returns WHERE company_id = NEW.company_id AND kind = NEW.kind
                     AND period_start <= NEW.period_end AND period_end >= NEW.period_start) THEN
            RAISE EXCEPTION 'A % return already covers part of % to %', NEW.kind, NEW.period_start, NEW.period_end USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        IF (NEW.company_id, NEW.kind, NEW.period_start, NEW.period_end, NEW.filed_on, NEW.reference, NEW.output_tax, NEW.input_tax, NEW.net,
            NEW.figures, NEW.filed_by, NEW.created_at)
           IS DISTINCT FROM (OLD.company_id, OLD.kind, OLD.period_start, OLD.period_end, OLD.filed_on, OLD.reference, OLD.output_tax, OLD.input_tax, OLD.net,
            OLD.figures, OLD.filed_by, OLD.created_at) THEN
            RAISE EXCEPTION 'A filed return does not change: correct it in a later period' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.settlement_entry_id IS NOT NULL AND (NEW.settlement_entry_id, NEW.settled_on) IS DISTINCT FROM (OLD.settlement_entry_id, OLD.settled_on) THEN
            RAISE EXCEPTION 'This return is already settled' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NEW.settlement_entry_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.settlement_entry_id
           AND source_type = 'tax_settlement' AND source_id = NEW.id AND entry_date = NEW.settled_on) THEN
        RAISE EXCEPTION 'A return is settled by its own settlement entry, dated when it was settled' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER tax_returns_valid BEFORE INSERT OR UPDATE ON tax_returns
    FOR EACH ROW EXECUTE FUNCTION tax_returns_valid();

CREATE FUNCTION journal_entries_tax_settlement_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.source_type = 'tax_settlement' AND NOT EXISTS (
        SELECT 1 FROM tax_returns WHERE id = NEW.source_id AND company_id = NEW.company_id AND period_end < NEW.entry_date) THEN
        RAISE EXCEPTION 'A tax settlement names a return of the company and is dated after its period' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_tax_settlement_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_tax_settlement_valid();

-- The tax accounts each kind of return reports.
CREATE FUNCTION tax_return_kind_of_account(p_system_key text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_system_key IN ('vat_payable', 'vat_receivable') THEN 'vat'
                WHEN p_system_key IN ('withholding_payable', 'withholding_receivable') THEN 'withholding' END
$$;

CREATE FUNCTION journal_lines_tax_period_open() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    tax_kind text;
    on_date date;
    filed record;
BEGIN
    SELECT tax_return_kind_of_account(a.system_key) INTO tax_kind FROM accounts a WHERE a.id = NEW.account_id;
    IF tax_kind IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT e.entry_date INTO on_date FROM journal_entries e WHERE e.company_id = NEW.company_id AND e.id = NEW.journal_entry_id;
    SELECT r.period_start, r.period_end, r.filed_on INTO filed FROM tax_returns r
     WHERE r.company_id = NEW.company_id AND r.kind = tax_kind AND on_date BETWEEN r.period_start AND r.period_end LIMIT 1;
    IF FOUND THEN
        RAISE EXCEPTION 'The % return for % to % is filed (%): date the entry after the period, or correct it in a later return',
            tax_kind, filed.period_start, filed.period_end, filed.filed_on USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_tax_period_open BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_lines_tax_period_open();

ALTER TABLE tax_returns ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tax_returns
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON tax_returns FROM {{app_role}};
GRANT SELECT, INSERT ON tax_returns TO {{app_role}};
GRANT UPDATE (settlement_entry_id, settled_on) ON tax_returns TO {{app_role}};

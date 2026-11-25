-- Evenbooks core schema, part 37: the adjustments period for the auditor (Module 6).
--
-- After a fiscal year is closed, the auditor's adjusting entries are posted into it without reopening it: each is dated
-- the year's last day (source 'adjustment', naming the year), and the year's closing entry is posted again so retained
-- earnings take the adjustment in. When the audit is signed off, the year's adjustments are finalised. Rules here:
--   1. An audit adjustment belongs to a closed fiscal year of the company that is not finalised, dated its last day.
--   2. An audit adjustment leaves alone the accounts kept by documents, as a manual journal does (032).
--   3. A year's adjustments are finalised only once it is closed; finalised, the year stays closed and that never
--      changes.

ALTER TABLE fiscal_years ADD COLUMN adjustments_closed_at timestamptz;
ALTER TABLE fiscal_years ADD COLUMN adjustments_closed_by uuid REFERENCES users (id);
ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_finalised_closed
    CHECK (adjustments_closed_at IS NULL OR (status = 'closed' AND adjustments_closed_by IS NOT NULL)) NOT VALID;

CREATE FUNCTION fiscal_years_finalised_fixed() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF OLD.adjustments_closed_at IS NOT NULL AND (NEW.adjustments_closed_at, NEW.adjustments_closed_by, NEW.status)
       IS DISTINCT FROM (OLD.adjustments_closed_at, OLD.adjustments_closed_by, OLD.status) THEN
        RAISE EXCEPTION 'The audit of the year to % is signed off: it stays closed as it is', OLD.ends_on USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER fiscal_years_finalised_fixed BEFORE UPDATE ON fiscal_years
    FOR EACH ROW EXECUTE FUNCTION fiscal_years_finalised_fixed();

CREATE FUNCTION journal_entries_adjustment_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.source_type = 'adjustment' AND NOT EXISTS (
        SELECT 1 FROM fiscal_years WHERE id = NEW.source_id AND company_id = NEW.company_id AND ends_on = NEW.entry_date
           AND status = 'closed' AND adjustments_closed_at IS NULL) THEN
        RAISE EXCEPTION 'An audit adjustment belongs to a closed fiscal year of the company, not signed off, and is dated its last day'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_adjustment_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_adjustment_valid();

-- 032, for audit adjustments too.
CREATE OR REPLACE FUNCTION journal_lines_manual_accounts() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    acc record;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type IN ('manual', 'adjustment')) THEN
        RETURN NEW;
    END IF;
    SELECT code, name, subtype, system_key INTO acc FROM accounts WHERE id = NEW.account_id;
    IF coalesce(acc.subtype, '') IN ('receivable', 'payable', 'inventory')
       OR coalesce(acc.system_key, '') IN ('accounts_receivable', 'accounts_payable', 'inventory', 'grni', 'undeposited_funds', 'retained_earnings') THEN
        RAISE EXCEPTION 'Account % % is kept by its documents: correct it through them, not with a manual journal', acc.code, acc.name
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

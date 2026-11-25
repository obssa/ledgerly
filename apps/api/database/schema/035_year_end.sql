-- Evenbooks core schema, part 35: the year-end close (Module 6).
--
-- Closing a fiscal year moves the year's profit or loss into retained earnings with one closing entry dated the year's
-- last day, so income and expense accounts start the next year at nothing. Rules here:
--   1. A closing entry (source 'closing') belongs to one fiscal year of the company and is dated its last day.
--   2. A fiscal year has at most one standing closing entry; reopening the year reverses it, and closing it again posts
--      a new one.
--   3. A fiscal year is closed only with its standing closing entry, and the books are locked through its end.

CREATE UNIQUE INDEX journal_entries_one_closing_per_year ON journal_entries (company_id, source_id)
    WHERE source_type = 'closing' AND status = 'posted';

CREATE FUNCTION journal_entries_closing_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.source_type = 'closing' AND NOT EXISTS (
        SELECT 1 FROM fiscal_years WHERE id = NEW.source_id AND company_id = NEW.company_id AND ends_on = NEW.entry_date) THEN
        RAISE EXCEPTION 'A closing entry belongs to a fiscal year of the company and is dated its last day' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_closing_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_closing_valid();

CREATE FUNCTION fiscal_years_closed_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.status = 'closed' AND OLD.status IS DISTINCT FROM 'closed' THEN
        IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND source_type = 'closing'
                         AND source_id = NEW.id AND status = 'posted') THEN
            RAISE EXCEPTION 'Fiscal year % to % closes with its closing entry', NEW.starts_on, NEW.ends_on USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM companies WHERE id = NEW.company_id AND books_closed_until >= NEW.ends_on) THEN
            RAISE EXCEPTION 'Lock the books through % before closing the year', NEW.ends_on USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER fiscal_years_closed_valid BEFORE UPDATE OF status ON fiscal_years
    FOR EACH ROW EXECUTE FUNCTION fiscal_years_closed_valid();

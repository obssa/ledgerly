-- Ledgerly core schema, part 34: month-end revaluation of foreign-currency balances (Module 6).
--
-- IAS 21: monetary items in a foreign currency are reported at the closing rate. A revaluation restates, at a date, the
-- base-currency value of every foreign-currency bank or wallet balance and every open foreign-currency invoice, credit
-- note, bill and supplier credit, booking the difference as an unrealised exchange gain or loss; it is reversed the next
-- day, so documents keep their own rates and each month starts again from them. Rules here:
--   1. One revaluation per company and date, made by the person signed in.
--   2. It names its entry (a 'revaluation' entry of the run) and the reversal of that entry; it is kept as made, with the
--      rates and the report it posted, and never changed or deleted.

CREATE TABLE fx_revaluations (
    id                uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    as_of             date          NOT NULL,
    rates             jsonb         NOT NULL CHECK (jsonb_typeof(rates) = 'object'),
    lines             jsonb         NOT NULL CHECK (jsonb_typeof(lines) = 'array'),
    net               numeric(20,2) NOT NULL,      -- gain (+) or loss (-) booked, base currency
    journal_entry_id  uuid          NOT NULL,
    reversal_entry_id uuid          NOT NULL,
    created_by        uuid          NOT NULL REFERENCES users (id),
    created_at        timestamptz   NOT NULL DEFAULT now(),
    UNIQUE (company_id, as_of),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id),
    FOREIGN KEY (company_id, reversal_entry_id) REFERENCES journal_entries (company_id, id)
);

CREATE FUNCTION fx_revaluations_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A revaluation is kept as it was made: its entry is reversed the next day already' USING ERRCODE = 'check_violation';
    END IF;
    IF app_user_id() IS NOT NULL AND NEW.created_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A revaluation is made by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id
                     AND source_type = 'revaluation' AND source_id = NEW.id AND entry_date = NEW.as_of) THEN
        RAISE EXCEPTION 'A revaluation names its own entry, dated the day it revalues' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.reversal_entry_id
                     AND source_type = 'reversal' AND source_id = NEW.journal_entry_id AND entry_date > NEW.as_of) THEN
        RAISE EXCEPTION 'A revaluation names the reversal of its entry, dated after it' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER fx_revaluations_valid BEFORE INSERT OR UPDATE OR DELETE ON fx_revaluations
    FOR EACH ROW EXECUTE FUNCTION fx_revaluations_valid();

ALTER TABLE fx_revaluations ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON fx_revaluations
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON fx_revaluations FROM {{app_role}};
GRANT SELECT, INSERT ON fx_revaluations TO {{app_role}};

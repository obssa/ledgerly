-- Evenbooks core schema, part 54: reminders of tax returns that are due (Module 7).
--
-- From the filing calendar (TaxCalendar: the country pack's due dates), the people who file tax get an email when a
-- return is due within ten days, and again the day it is overdue, unless the company turned the reminders off
-- (companies.settings.tax_reminders = false). Rules here:
--   1. Each return period is reminded once per stage (due soon, overdue); the record is kept as sent.
--   2. The scheduler, outside any company, learns only which companies to look at: tax_reminder_companies() lists
--      companies in use (not archived, subscription not suspended or cancelled), never the shared demo.

CREATE TABLE tax_reminders (
    id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id   uuid        NOT NULL REFERENCES companies (id),
    kind         text        NOT NULL CHECK (kind IN ('vat', 'withholding', 'tot')),
    period_start date        NOT NULL,
    period_end   date        NOT NULL CHECK (period_end >= period_start),
    due_on       date        NOT NULL,
    stage        text        NOT NULL CHECK (stage IN ('due_soon', 'overdue')),
    sent_to      integer     NOT NULL CHECK (sent_to >= 0),
    sent_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, kind, period_start, stage)
);

CREATE FUNCTION tax_reminders_kept() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'A tax reminder is kept as sent' USING ERRCODE = 'insufficient_privilege';
END $$;
CREATE TRIGGER tax_reminders_kept BEFORE UPDATE OR DELETE ON tax_reminders
    FOR EACH ROW EXECUTE FUNCTION tax_reminders_kept();

CREATE FUNCTION tax_reminder_companies() RETURNS TABLE (company_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT c.id FROM companies c JOIN organisations o ON o.id = c.organisation_id
     WHERE app_company_id() IS NULL AND app_user_id() IS NULL
       AND c.status <> 'archived' AND o.status NOT IN ('suspended', 'cancelled')
       AND coalesce(c.settings ->> 'tax_reminders', 'true') <> 'false'
       AND NOT EXISTS (SELECT 1 FROM demo_accounts d WHERE d.company_id = c.id)
     ORDER BY c.id
     LIMIT 5000
$$;
REVOKE ALL ON FUNCTION tax_reminder_companies() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tax_reminder_companies() TO {{app_role}};

ALTER TABLE tax_reminders ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tax_reminders
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON tax_reminders FROM {{app_role}};
GRANT SELECT, INSERT ON tax_reminders TO {{app_role}};

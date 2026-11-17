-- Ledgerly core schema, part 48: payment reminders for overdue invoices (Module 2, collections).
--
-- A company may turn on reminders (companies.settings.reminders: {"enabled": true, "days": [7, 30]}): an email to
-- the customer a set number of days after an invoice falls due, while something is still owed. Someone may also send
-- one by hand. Each reminder sent is recorded here. Rules:
--   1. A reminder is for a posted invoice of the same company with something still owed.
--   2. The record is kept as sent: never changed or removed.
--   3. A scheduled reminder goes once per invoice and step (days after the due date).
--   4. The scheduler, outside any company, learns only which invoice is due which step: invoice_reminders_due().

CREATE TABLE invoice_reminders (
    id          bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id  uuid        NOT NULL REFERENCES companies (id),
    document_id uuid        NOT NULL REFERENCES documents (id),
    kind        text        NOT NULL CHECK (kind IN ('scheduled', 'manual')),
    step        integer     CHECK (step BETWEEN 1 AND 365),
    sent_to     text        NOT NULL CHECK (length(sent_to) BETWEEN 3 AND 190),
    sent_by     uuid        REFERENCES users (id),
    sent_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'scheduled') = (step IS NOT NULL)),
    CHECK (kind = 'scheduled' OR sent_by IS NOT NULL)
);
CREATE UNIQUE INDEX invoice_reminders_once ON invoice_reminders (document_id, step) WHERE kind = 'scheduled';
CREATE INDEX invoice_reminders_document ON invoice_reminders (company_id, document_id, sent_at);

CREATE FUNCTION invoice_reminders_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A reminder is kept as it was sent' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM documents d
                    WHERE d.id = NEW.document_id AND d.company_id = NEW.company_id AND d.type = 'invoice'
                      AND d.status IN ('posted', 'part_paid') AND d.amount_due > 0) THEN
        RAISE EXCEPTION 'A reminder is for a posted invoice of the company with something still owed' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER invoice_reminders_valid BEFORE INSERT OR UPDATE OR DELETE ON invoice_reminders
    FOR EACH ROW EXECUTE FUNCTION invoice_reminders_valid();

-- Invoices owed, with a customer email, whose due date plus a step of the company's reminders fell in the last week
-- up to p_on, and not reminded at that step yet (a missed day catches up; a step older than a week is let go).
CREATE FUNCTION invoice_reminders_due(p_on date)
RETURNS TABLE (company_id uuid, document_id uuid, step integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT d.company_id, d.id, s.step
      FROM companies c
      CROSS JOIN LATERAL (SELECT (v #>> '{}')::integer AS step
                            FROM jsonb_array_elements(CASE WHEN jsonb_typeof(c.settings -> 'reminders' -> 'days') = 'array'
                                                           THEN c.settings -> 'reminders' -> 'days' ELSE '[]'::jsonb END) v
                           WHERE jsonb_typeof(v) = 'number' AND (v #>> '{}') ~ '^[0-9]{1,3}$') s
      JOIN documents d ON d.company_id = c.id
      JOIN contacts k ON k.id = d.contact_id
     WHERE app_company_id() IS NULL AND app_user_id() IS NULL
       AND c.status <> 'archived' AND (c.settings -> 'reminders' ->> 'enabled') = 'true'
       AND s.step BETWEEN 1 AND 365
       AND d.type = 'invoice' AND d.status IN ('posted', 'part_paid') AND d.amount_due > 0 AND d.due_date IS NOT NULL
       AND d.due_date + s.step <= p_on AND d.due_date + s.step > p_on - 7
       AND coalesce(k.email, '') <> ''
       AND NOT EXISTS (SELECT 1 FROM invoice_reminders r WHERE r.document_id = d.id AND r.kind = 'scheduled' AND r.step = s.step)
     ORDER BY d.company_id, d.due_date, d.id, s.step
     LIMIT 2000
$$;
REVOKE ALL ON FUNCTION invoice_reminders_due(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION invoice_reminders_due(date) TO {{app_role}};

ALTER TABLE invoice_reminders ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invoice_reminders
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invoice_reminders FROM {{app_role}};
GRANT SELECT, INSERT ON invoice_reminders TO {{app_role}};

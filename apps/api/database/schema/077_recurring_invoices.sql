-- Evenbooks core schema, part 77: recurring invoices (Module 2).
--
-- A recurring invoice repeats an invoice on a schedule: the monthly retainer, the rent a tenant pays, a subscription.
-- Each time it comes due it makes an ordinary invoice (dated that day, due by the customer's terms) from the template's
-- lines, left as a draft or posted, and the invoice names the template. Rules:
--   1. A template is for one of the company's customers, repeats weekly, monthly, quarterly or yearly from its next
--      date until its end date if it has one, and has at least one line.
--   2. A template makes its invoices as the person signed in when it was set up or resumed: never as someone else.
--   3. An invoice made from a template is an invoice of the template's company and customer.

CREATE TABLE recurring_invoices (
    id              uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id      uuid          NOT NULL REFERENCES companies (id),
    name            text          NOT NULL CHECK (length(btrim(name)) BETWEEN 3 AND 120),
    contact_id      uuid          NOT NULL REFERENCES contacts (id),
    currency        char(3)       NOT NULL,
    prices_include_tax boolean    NOT NULL DEFAULT false,
    warehouse_id    uuid          REFERENCES warehouses (id),
    notes           text          CHECK (length(notes) <= 2000),
    lines           jsonb         NOT NULL CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 1 AND 100),
    frequency       text          NOT NULL CHECK (frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')),
    next_on         date          NOT NULL,
    ends_on         date,
    post            boolean       NOT NULL DEFAULT false,   -- posted when made, or left as a draft to check
    is_active       boolean       NOT NULL DEFAULT true,
    made_count      integer       NOT NULL DEFAULT 0 CHECK (made_count >= 0),
    last_made_on    date,
    last_error      text          CHECK (length(last_error) <= 500),
    posts_as        uuid          NOT NULL REFERENCES users (id),
    created_at      timestamptz   NOT NULL DEFAULT now(),
    updated_at      timestamptz   NOT NULL DEFAULT now(),
    CHECK (ends_on IS NULL OR NOT is_active OR next_on <= ends_on)
);
CREATE INDEX recurring_invoices_due_idx ON recurring_invoices (next_on) WHERE is_active;

ALTER TABLE documents ADD COLUMN recurring_invoice_id uuid REFERENCES recurring_invoices (id),
    ADD CONSTRAINT documents_recurring_is_invoice CHECK (recurring_invoice_id IS NULL OR type = 'invoice');

-- Rules 1 and 2.
CREATE FUNCTION recurring_invoices_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id AND is_customer) THEN
        RAISE EXCEPTION 'A recurring invoice is for one of the company''s customers' USING ERRCODE = 'check_violation';
    END IF;
    IF app_user_id() IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.posts_as IS DISTINCT FROM OLD.posts_as)
       AND NEW.posts_as IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'A recurring invoice makes its invoices as the person who set it up or resumed it' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION recurring_invoices_valid() FROM PUBLIC;
CREATE TRIGGER recurring_invoices_valid BEFORE INSERT OR UPDATE ON recurring_invoices FOR EACH ROW EXECUTE FUNCTION recurring_invoices_valid();

-- Rule 3.
CREATE FUNCTION documents_recurring_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    t record;
BEGIN
    IF NEW.recurring_invoice_id IS NULL OR (TG_OP = 'UPDATE' AND NEW.recurring_invoice_id IS NOT DISTINCT FROM OLD.recurring_invoice_id) THEN
        RETURN NEW;
    END IF;
    SELECT company_id, contact_id INTO t FROM recurring_invoices WHERE id = NEW.recurring_invoice_id;
    IF NOT FOUND OR t.company_id <> NEW.company_id OR t.contact_id <> NEW.contact_id THEN
        RAISE EXCEPTION 'An invoice made from a recurring invoice is for its company and customer' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION documents_recurring_valid() FROM PUBLIC;
CREATE TRIGGER documents_recurring_valid BEFORE INSERT OR UPDATE OF recurring_invoice_id, contact_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_recurring_valid();

-- The scheduler (ledgerly:make-recurring-invoices) runs outside any company: this answers only which templates are due
-- and who set them up, ids only, and nothing inside a company's context (as recurring_journals_due, 036).
CREATE FUNCTION recurring_invoices_due(p_on date)
RETURNS TABLE (company_id uuid, template_id uuid, posts_as uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT r.company_id, r.id, r.posts_as FROM recurring_invoices r
     WHERE r.is_active AND r.next_on <= p_on AND app_company_id() IS NULL AND app_user_id() IS NULL
     ORDER BY r.next_on, r.id LIMIT 1000
$$;
REVOKE ALL ON FUNCTION recurring_invoices_due(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION recurring_invoices_due(date) TO {{app_role}};

ALTER TABLE recurring_invoices ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON recurring_invoices
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON recurring_invoices FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON recurring_invoices TO {{app_role}};

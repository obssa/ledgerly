-- Evenbooks core schema, part 109: QuickBooks' Terms list.
--
-- A term sets a document's due date from its date (app/Domain/Sales/PaymentTerms.php, mirrored by the web app's
-- lib/terms.ts): a number of days ("Net 30"), or a day of the month in the company's calendar ("Due end of month", the
-- 10th), due the next month when the document is dated within so many days of it. A customer or supplier has its
-- usual term; a document keeps the term it was given, and its due date as typed (a person may change the date and the
-- term stays). Terms are turned off, never deleted, so a document's term always reads back.

CREATE TABLE payment_terms (
    id                uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid        NOT NULL REFERENCES companies (id),
    name              text        NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 60),
    kind              text        NOT NULL CHECK (kind IN ('days', 'day_of_month')),
    days              integer     CHECK (days BETWEEN 0 AND 365),
    day_of_month      integer     CHECK (day_of_month BETWEEN 1 AND 31),   -- 31: the month's last day, whatever its length
    next_month_within integer     NOT NULL DEFAULT 0 CHECK (next_month_within BETWEEN 0 AND 31),
    is_active         boolean     NOT NULL DEFAULT true,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'days' AND days IS NOT NULL AND day_of_month IS NULL AND next_month_within = 0)
        OR (kind = 'day_of_month' AND day_of_month IS NOT NULL AND days IS NULL))
);
CREATE UNIQUE INDEX payment_terms_name ON payment_terms (company_id, lower(name));

ALTER TABLE payment_terms ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON payment_terms
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON payment_terms FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON payment_terms TO {{app_role}};   -- turned off, never deleted

-- A term stays in the company it was made in.
CREATE FUNCTION payment_terms_company_fixed() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.company_id <> OLD.company_id THEN
        RAISE EXCEPTION 'A term stays in its company' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payment_terms_company_fixed BEFORE UPDATE ON payment_terms
    FOR EACH ROW EXECUTE FUNCTION payment_terms_company_fixed();

ALTER TABLE contacts ADD COLUMN payment_terms_id uuid REFERENCES payment_terms (id);
ALTER TABLE documents ADD COLUMN payment_terms_id uuid REFERENCES payment_terms (id);

-- A customer's, supplier's or document's term is one of its own company's.
CREATE FUNCTION payment_terms_same_company() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.payment_terms_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM payment_terms t WHERE t.id = NEW.payment_terms_id AND t.company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'The term is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER contacts_payment_terms_same_company BEFORE INSERT OR UPDATE OF payment_terms_id, company_id ON contacts
    FOR EACH ROW EXECUTE FUNCTION payment_terms_same_company();
CREATE TRIGGER documents_payment_terms_same_company BEFORE INSERT OR UPDATE OF payment_terms_id, company_id ON documents
    FOR EACH ROW EXECUTE FUNCTION payment_terms_same_company();

-- Every company starts with QuickBooks' usual terms (new companies get them in SetUpCompany), plus a "Net N" for any
-- other number of days its customers and suppliers already have; each contact is linked to the term of its days.
INSERT INTO payment_terms (company_id, name, kind, days, day_of_month)
SELECT c.id, t.name, t.kind, t.days, t.dom
  FROM companies c
 CROSS JOIN (VALUES ('Due on receipt', 'days', 0, NULL::integer), ('Net 15', 'days', 15, NULL), ('Net 30', 'days', 30, NULL),
                    ('Net 60', 'days', 60, NULL), ('Due end of month', 'day_of_month', NULL, 31)) AS t (name, kind, days, dom);

INSERT INTO payment_terms (company_id, name, kind, days)
SELECT DISTINCT k.company_id, 'Net ' || k.payment_terms_days, 'days', k.payment_terms_days
  FROM contacts k
 WHERE k.payment_terms_days NOT IN (0, 15, 30, 60) AND k.payment_terms_days <= 365
ON CONFLICT DO NOTHING;

UPDATE contacts k SET payment_terms_id = t.id
  FROM payment_terms t
 WHERE t.company_id = k.company_id AND t.kind = 'days' AND t.days = k.payment_terms_days
   AND t.name = CASE k.payment_terms_days WHEN 0 THEN 'Due on receipt' ELSE 'Net ' || k.payment_terms_days END;

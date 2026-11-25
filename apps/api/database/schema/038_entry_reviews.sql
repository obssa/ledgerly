-- Evenbooks core schema, part 38: the review queue of unusual entries (Module 6, accountant tools).
--
-- The queue flags entries worth a second look (a manual journal moving cash, a large or round amount, an entry posted
-- long after its date or at night, opening balance equity used after the conversion). Whoever reviews one marks it
-- reviewed, with an optional note, and it leaves the queue. Rules here:
--   1. An entry is reviewed once, by the person signed in, in its own company.
--   2. A review is kept as it was: never changed or removed (the application role may only add one).

CREATE TABLE journal_entry_reviews (
    company_id       uuid        NOT NULL REFERENCES companies (id),
    journal_entry_id uuid        NOT NULL,
    reviewed_by      uuid        NOT NULL REFERENCES users (id),
    reviewed_at      timestamptz NOT NULL DEFAULT now(),
    flags            text[]      NOT NULL DEFAULT '{}' CHECK (cardinality(flags) <= 20),   -- what the queue showed when it was reviewed
    note             text        CHECK (length(note) <= 500),
    PRIMARY KEY (company_id, journal_entry_id),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
);

CREATE FUNCTION journal_entry_reviews_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF app_user_id() IS NOT NULL AND NEW.reviewed_by IS DISTINCT FROM app_user_id() THEN
        RAISE EXCEPTION 'An entry is reviewed by the person signed in' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entry_reviews_valid BEFORE INSERT ON journal_entry_reviews
    FOR EACH ROW EXECUTE FUNCTION journal_entry_reviews_valid();

ALTER TABLE journal_entry_reviews ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON journal_entry_reviews
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON journal_entry_reviews FROM {{app_role}};
GRANT SELECT, INSERT ON journal_entry_reviews TO {{app_role}};

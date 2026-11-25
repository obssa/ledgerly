-- Evenbooks core schema, part 56: quotes and sales orders (Module 2).
--
-- A quote is drafted, sent to the customer (it takes its number then), accepted or declined, expires after its
-- valid-until date (due_date) while it waits for an answer, and is converted to a sales order or an invoice. A sales
-- order is confirmed (accepted) and converted to an invoice. Neither posts anything; their states are the quote
-- machine of 006.
--
-- The document made from a quote or an order names it (source_document_id). One quote or order makes one live
-- document; it is marked converted only once that document exists, and the link never changes afterwards.

-- A confirmed order, or an accepted quote, can still be cancelled before it is converted
-- (config/state_machines.php mirrors this).
INSERT INTO state_transitions (machine, from_state, to_state, action) VALUES ('quote', 'accepted', 'void', 'Cancel');

ALTER TABLE documents ADD COLUMN source_document_id uuid REFERENCES documents (id);
ALTER TABLE documents ADD CONSTRAINT documents_source_for_sales
    CHECK (source_document_id IS NULL OR type IN ('sales_order', 'invoice'));
CREATE UNIQUE INDEX documents_one_per_source ON documents (company_id, source_document_id)
    WHERE source_document_id IS NOT NULL AND status <> 'void';

-- Quotes and orders owe nothing: amount_due stays 0 whatever their total.
ALTER TABLE documents ADD CONSTRAINT documents_quotes_owe_nothing
    CHECK (type NOT IN ('quote', 'sales_order') OR amount_due = 0);

CREATE FUNCTION documents_source_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    src record;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.source_document_id IS DISTINCT FROM OLD.source_document_id THEN
        -- Set once, on a draft (the conversion saves the draft, then names its source); never changed or removed.
        IF OLD.source_document_id IS NOT NULL OR OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'The quote or order a document was made from does not change' USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    IF NEW.source_document_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.source_document_id IS DISTINCT FROM OLD.source_document_id) THEN
        SELECT type, contact_id, status INTO src
          FROM documents WHERE id = NEW.source_document_id AND company_id = NEW.company_id;
        IF NOT FOUND OR NOT (src.type = 'quote' OR (src.type = 'sales_order' AND NEW.type = 'invoice')) THEN
            RAISE EXCEPTION 'A sales order is made from a quote, an invoice from a quote or a sales order, of the same company'
                USING ERRCODE = 'check_violation';
        END IF;
        IF src.contact_id <> NEW.contact_id THEN
            RAISE EXCEPTION 'A document made from a quote or order is for the same customer' USING ERRCODE = 'check_violation';
        END IF;
        IF src.status <> 'accepted' THEN
            RAISE EXCEPTION 'Only an accepted quote or a confirmed order is converted' USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    -- A quote or order is converted only once the document made from it exists.
    IF TG_OP = 'UPDATE' AND NEW.status = 'converted' AND OLD.status <> 'converted'
       AND NOT EXISTS (SELECT 1 FROM documents d
                        WHERE d.company_id = NEW.company_id AND d.source_document_id = NEW.id AND d.status <> 'void') THEN
        RAISE EXCEPTION 'A quote or order is converted by making the document from it' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_source_valid BEFORE INSERT OR UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_source_valid();

-- Quotes sent and still unanswered whose valid-until date is before p_on, for the daily expiry
-- (ledgerly:expire-quotes, which checks each company's own today). Ids only, and only outside a tenant.
CREATE FUNCTION quotes_expiring(p_on date)
RETURNS TABLE (company_id uuid, document_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT d.company_id, d.id
      FROM documents d
      JOIN companies c ON c.id = d.company_id
     WHERE app_company_id() IS NULL AND app_user_id() IS NULL
       AND c.status <> 'archived'
       AND d.type = 'quote' AND d.status = 'sent' AND d.due_date IS NOT NULL AND d.due_date < p_on
     ORDER BY d.company_id, d.due_date, d.id
     LIMIT 5000
$$;
REVOKE ALL ON FUNCTION quotes_expiring(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION quotes_expiring(date) TO {{app_role}};

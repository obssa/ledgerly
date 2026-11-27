-- Evenbooks core schema, part 111: QuickBooks' classes and locations on invoices and bills, and a line's service date.
--
-- A company tracks classes and locations (Settings: off, one per transaction, or one per line). A document has its
-- location (014) and now its class; a line may have its own of each, and its service date (QuickBooks' Service date
-- column). Each is one of the company's own dimensions of the right kind (014's dimension_belongs). Posting carries
-- them to the ledger lines, so profit by class and by location include invoices and bills. A posted document's lines
-- and header are frozen as before (005), these columns with them.

ALTER TABLE documents ADD COLUMN class_id uuid;
ALTER TABLE document_lines ADD COLUMN service_date date;
ALTER TABLE document_lines ADD COLUMN class_id uuid;
ALTER TABLE document_lines ADD COLUMN location_id uuid;

CREATE OR REPLACE FUNCTION documents_location() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT dimension_belongs(NEW.company_id, 'location', NEW.location_id) THEN
        RAISE EXCEPTION 'A document names a location that is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF NOT dimension_belongs(NEW.company_id, 'class', NEW.class_id) THEN
        RAISE EXCEPTION 'A document names a class that is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER documents_location ON documents;
CREATE TRIGGER documents_location BEFORE INSERT OR UPDATE OF location_id, class_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_location();

CREATE FUNCTION document_lines_dimensions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT (dimension_belongs(NEW.company_id, 'location', NEW.location_id) AND dimension_belongs(NEW.company_id, 'class', NEW.class_id)) THEN
        RAISE EXCEPTION 'A document line names a location or class that is not one of this company''s' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_lines_dimensions BEFORE INSERT OR UPDATE OF location_id, class_id ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_dimensions();

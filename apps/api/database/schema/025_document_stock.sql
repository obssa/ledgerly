-- Evenbooks core schema, part 25: stock on invoices and bills (Module 4).
--
-- An invoice or bill with stock items names the warehouse the goods leave from or arrive in. On posting, each stock
-- line moves stock (024 values it): a bill brings goods in at their cost (Dr Inventory instead of an expense), an
-- invoice takes them out at weighted average (Dr Cost of goods sold, Cr Inventory, in the invoice's entry). Rules here:
--   1. A document's warehouse is one of the company's own.
--   2. A line's item is one of the company's own items.
--   3. A stock move names the document it belongs to only when that document is the company's own.

ALTER TABLE documents ADD COLUMN warehouse_id uuid REFERENCES warehouses (id);

CREATE FUNCTION documents_warehouse_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.warehouse_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A document''s warehouse is one of the company''s own' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_warehouse_valid BEFORE INSERT OR UPDATE OF warehouse_id, company_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_warehouse_valid();

CREATE FUNCTION document_lines_item_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A line''s item is one of the company''s own' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_lines_item_valid BEFORE INSERT OR UPDATE OF item_id, company_id ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_item_valid();

CREATE OR REPLACE FUNCTION stock_moves_document_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.document_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A stock move names one of the company''s own documents' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER stock_moves_document_valid BEFORE INSERT ON stock_moves FOR EACH ROW EXECUTE FUNCTION stock_moves_document_valid();

CREATE INDEX stock_moves_document_idx ON stock_moves (company_id, document_id) WHERE document_id IS NOT NULL;

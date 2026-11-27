-- Evenbooks core schema, part 110: QuickBooks' "Amounts are": Exclusive of tax, Inclusive of tax, Out of scope of tax.
--
-- Exclusive and inclusive are prices_include_tax (003), as before. Out of scope of tax is a document VAT does not
-- touch at all (a sale outside the tax's reach, a supplier not registered for it): its lines carry no tax rate and no
-- tax, and it is left off the VAT worksheet and return (TaxReturns::vatSide). Zero-rated and exempt are tax rates, not
-- this: they stay on the return. A flag beside prices_include_tax, so posted documents need no rewriting.

ALTER TABLE documents ADD COLUMN out_of_scope boolean NOT NULL DEFAULT false;
ALTER TABLE documents ADD CONSTRAINT documents_out_of_scope_untaxed
    CHECK (NOT out_of_scope OR (NOT prices_include_tax AND tax_total = 0));

ALTER TABLE recurring_invoices ADD COLUMN out_of_scope boolean NOT NULL DEFAULT false;
ALTER TABLE recurring_invoices ADD CONSTRAINT recurring_invoices_out_of_scope_exclusive
    CHECK (NOT out_of_scope OR NOT prices_include_tax);

-- A line of an out-of-scope document has no tax rate and no tax.
CREATE FUNCTION document_lines_out_of_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.tax_rate_id IS NOT NULL OR NEW.line_tax <> 0)
       AND EXISTS (SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.out_of_scope) THEN
        RAISE EXCEPTION 'A document out of scope of tax has no tax on its lines' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_lines_out_of_scope BEFORE INSERT OR UPDATE OF tax_rate_id, line_tax, document_id ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_out_of_scope();

-- And a document made out of scope keeps no taxed line: checked when the saving transaction commits, after its lines
-- are written again.
CREATE FUNCTION documents_out_of_scope_lines() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.out_of_scope AND EXISTS (
        SELECT 1 FROM document_lines l WHERE l.document_id = NEW.id AND (l.tax_rate_id IS NOT NULL OR l.line_tax <> 0)) THEN
        RAISE EXCEPTION 'A document out of scope of tax has no tax on its lines' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER documents_out_of_scope_lines AFTER INSERT OR UPDATE OF out_of_scope ON documents
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION documents_out_of_scope_lines();

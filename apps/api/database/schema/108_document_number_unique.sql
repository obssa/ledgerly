-- 108: document numbers as QuickBooks gives them. A document takes its number when it is saved, the next in the
-- sequence unless the person types their own ("Custom transaction numbers", allowed per type, on by default). A number
-- is unique per company and kind of document whatever its letter case (INV-1 and inv-1 are the same number), and the
-- sequence skips any number already typed (DocumentNumbers::take), so it never runs into one.

ALTER TABLE document_numbering
    ADD COLUMN allow_custom boolean NOT NULL DEFAULT true;

CREATE UNIQUE INDEX documents_number_unique_any_case ON documents (company_id, type, lower(number)) WHERE number IS NOT NULL;

-- Evenbooks core schema, part 113: QuickBooks' discount and deposit on sales documents.
--
-- Discount: a percentage of the subtotal or an amount, given before tax. It is shared out over the lines in proportion
-- (the last positive line takes the rounding), so each line's tax is worked out on what is really charged: a line's
-- line_subtotal is its taxable amount after its share (discount_share), and the document's subtotal is the lines' sum,
-- so total = subtotal + tax as everywhere. What it was before the discount is subtotal + discount_total. Posting
-- credits income before the discount and debits it to the company's Discounts given account (QuickBooks).
--
-- Deposit: money the customer paid with the order, received into a bank, cash or mobile-money account when the invoice
-- is posted, as a payment applied to it (the payment names the invoice in deposit_document_id). Balance due is the rest.

ALTER TABLE documents
    ADD COLUMN discount_type      text          CHECK (discount_type IN ('percent', 'amount')),
    ADD COLUMN discount_value     numeric(20,6) CHECK (discount_value >= 0),
    ADD COLUMN discount_total     numeric(20,2) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
    ADD COLUMN deposit_amount     numeric(20,2) NOT NULL DEFAULT 0 CHECK (deposit_amount >= 0),
    ADD COLUMN deposit_account_id uuid REFERENCES accounts (id),
    ADD CONSTRAINT documents_discount_whole CHECK ((discount_type IS NULL) = (discount_value IS NULL) AND (discount_type IS NOT NULL OR discount_total = 0)),
    ADD CONSTRAINT documents_discount_percent CHECK (discount_type IS DISTINCT FROM 'percent' OR discount_value <= 100),
    ADD CONSTRAINT documents_deposit_whole CHECK ((deposit_amount = 0) = (deposit_account_id IS NULL) AND deposit_amount <= total);

ALTER TABLE document_lines ADD COLUMN discount_share numeric(20,2) NOT NULL DEFAULT 0 CHECK (discount_share >= 0);

ALTER TABLE payments ADD COLUMN deposit_document_id uuid REFERENCES documents (id);

-- A deposit goes into one of the company's own active bank, cash or mobile-money accounts.
CREATE FUNCTION documents_deposit_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.deposit_account_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM accounts a WHERE a.id = NEW.deposit_account_id AND a.company_id = NEW.company_id
           AND a.subtype IN ('bank', 'cash', 'mobile_money')) THEN
        RAISE EXCEPTION 'A deposit goes into a bank, cash or mobile-money account of this company' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_deposit_account BEFORE INSERT OR UPDATE OF deposit_account_id, company_id ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_deposit_account();

-- A deposit payment is one of its invoice's: the same company and customer.
CREATE FUNCTION payments_deposit_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.deposit_document_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents d WHERE d.id = NEW.deposit_document_id AND d.company_id = NEW.company_id
           AND d.contact_id = NEW.contact_id AND d.type = 'invoice' AND NEW.direction = 'in') THEN
        RAISE EXCEPTION 'A deposit is received from the invoice''s own customer' USING ERRCODE = 'foreign_key_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payments_deposit_document BEFORE INSERT ON payments
    FOR EACH ROW EXECUTE FUNCTION payments_deposit_document();

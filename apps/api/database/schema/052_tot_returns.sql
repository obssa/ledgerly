-- Ledgerly core schema, part 52: turnover tax returns (Module 7).
--
-- In a country whose pack has a turnover tax (TOT), sales taxed at a TOT rate post their tax to TOT payable
-- (ApproveInvoice). A TOT return is filed and settled like a VAT return (039): its period then locks TOT payable as
-- the VAT return locks the VAT accounts. No rate is set here: rates come from the country pack or the company, and no
-- rate goes live without the country's tax adviser.

ALTER TABLE tax_returns DROP CONSTRAINT tax_returns_kind_check;
ALTER TABLE tax_returns ADD CONSTRAINT tax_returns_kind_check CHECK (kind IN ('vat', 'withholding', 'tot'));

CREATE OR REPLACE FUNCTION tax_return_kind_of_account(p_system_key text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_system_key IN ('vat_payable', 'vat_receivable') THEN 'vat'
                WHEN p_system_key IN ('withholding_payable', 'withholding_receivable') THEN 'withholding'
                WHEN p_system_key = 'tot_payable' THEN 'tot' END
$$;

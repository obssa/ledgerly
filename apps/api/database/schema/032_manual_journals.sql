-- Evenbooks core schema, part 32: manual journal entries (Module 6).
--
-- A manual journal (source 'manual') is the accountant's own entry: an accrual, a correction, a reclassification. It
-- never touches an account another part of the books keeps by its documents, because the documents and the account would
-- then disagree:
--   - receivables and payables (invoices, bills, payments and credits keep them, customer by customer);
--   - inventory and goods received not invoiced (stock moves keep them, item by item);
--   - undeposited funds (receipts and deposits keep it);
--   - retained earnings (the year-end close keeps it).
-- Those are corrected through their own documents: a credit note, a stock adjustment, a deposit.

CREATE FUNCTION journal_lines_manual_accounts() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    acc record;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type = 'manual') THEN
        RETURN NEW;
    END IF;
    SELECT code, name, subtype, system_key INTO acc FROM accounts WHERE id = NEW.account_id;
    IF coalesce(acc.subtype, '') IN ('receivable', 'payable', 'inventory')
       OR coalesce(acc.system_key, '') IN ('accounts_receivable', 'accounts_payable', 'inventory', 'grni', 'undeposited_funds', 'retained_earnings') THEN
        RAISE EXCEPTION 'Account % % is kept by its documents: correct it through them, not with a manual journal', acc.code, acc.name
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_manual_accounts BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_lines_manual_accounts();

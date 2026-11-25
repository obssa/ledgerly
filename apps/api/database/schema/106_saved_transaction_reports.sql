-- Ledgerly core schema, part 106: the transaction reports (Transaction list by date, Invoice list, Open invoices, Bill
-- list, Unpaid bills, Payments received and made) can be saved and scheduled, with their Customize choices kept in the
-- options (columns, sort, grouping, status: each checked by the API against its own list, SavedReports::OPTIONS).
ALTER TABLE saved_reports DROP CONSTRAINT saved_reports_report_check;
ALTER TABLE saved_reports ADD CONSTRAINT saved_reports_report_check CHECK (report IN ('profit-and-loss', 'balance-sheet', 'trial-balance',
    'general-ledger', 'cash-flow', 'changes-in-equity', 'financial-health', 'profit-by-dimension', 'sales', 'purchases', 'ar-ageing',
    'ap-ageing', 'inventory-valuation', 'stock-movement', 'stock-ageing', 'custom',
    'transaction-list', 'invoice-list', 'open-invoices', 'bill-list', 'unpaid-bills', 'payments-received', 'payments-made'));

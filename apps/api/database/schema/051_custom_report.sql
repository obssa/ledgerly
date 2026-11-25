-- Evenbooks core schema, part 51: the custom report can be saved and scheduled like the others (Module 10).
-- Its choices are kept in saved_reports.options, checked by the API against fixed lists (SavedReports::OPTIONS).

ALTER TABLE saved_reports DROP CONSTRAINT saved_reports_report_check;
ALTER TABLE saved_reports ADD CONSTRAINT saved_reports_report_check CHECK (report IN ('profit-and-loss', 'balance-sheet', 'trial-balance',
    'general-ledger', 'cash-flow', 'changes-in-equity', 'financial-health', 'profit-by-dimension', 'sales', 'purchases', 'ar-ageing',
    'ap-ageing', 'inventory-valuation', 'stock-movement', 'stock-ageing', 'custom'));

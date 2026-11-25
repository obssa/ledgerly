-- Evenbooks core schema, part 105: saved and scheduled reports roll with QuickBooks' report periods.
--
-- A saved report keeps a custom range (its dates) or a rolling period, worked out each time in the company's calendar
-- (app/Support/ReportPeriods.php, the same rules as the web app's period list). 045 allowed five rolling periods; now
-- every period the report pages offer. The rest of 045 holds: a custom period has its dates, a rolling one has none.
ALTER TABLE saved_reports DROP CONSTRAINT saved_reports_period_check;
ALTER TABLE saved_reports ADD CONSTRAINT saved_reports_period_check CHECK (period IN ('custom', 'year_to_date',
    'all', 'today', 'yesterday', 'this_week', 'this_week_to_date', 'last_week', 'last_week_to_date', 'next_week', 'next_4_weeks',
    'this_month', 'this_month_to_date', 'last_month', 'last_month_to_date', 'next_month',
    'this_quarter', 'this_quarter_to_date', 'last_quarter', 'last_quarter_to_date', 'next_quarter',
    'this_year', 'this_year_to_date', 'this_year_to_last_month', 'last_year', 'last_year_to_date', 'next_year',
    'this_fiscal_year', 'this_fiscal_year_to_date', 'this_fiscal_year_to_last_month', 'last_fiscal_year', 'last_fiscal_year_to_date',
    'this_fiscal_quarter', 'this_fiscal_quarter_to_date', 'last_fiscal_quarter',
    'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days', 'last_12_months',
    'since_30_days_ago', 'since_60_days_ago', 'since_90_days_ago', 'since_365_days_ago'));

-- 045's "this month" and "this quarter" ran to today; the report pages' This month and This quarter are the whole of
-- them (as QuickBooks'). Reports saved before keep what they showed: they become "… to date".
UPDATE saved_reports SET period = 'this_month_to_date' WHERE period = 'this_month';
UPDATE saved_reports SET period = 'this_quarter_to_date' WHERE period = 'this_quarter';

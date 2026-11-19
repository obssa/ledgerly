/**
 * Typed client for the Ledgerly API (apps/api/routes/api.php). Amounts are decimal strings, never numbers.
 * Without LEDGERLY_API_URL the app runs in demo mode on sample data (src/lib/demo.ts).
 */

export interface Company {
  id: string;
  /** The paying organisation; several companies can share one (multi-company). */
  organisation_id: string;
  legal_name: string;
  trading_name: string | null;
  country_code: string;
  base_currency: string;
}

/** GET /company: the profile and fiscal settings of the company being worked in (Module 1). */
export interface CompanySettings {
  id: string;
  /** Send back on save: the API refuses the save (409) if someone else changed the settings first. */
  version: string;
  country_code: string;
  framework: string;
  status: string;
  legal_name: string;
  trading_name: string | null;
  legal_form: string | null;
  industry: string | null;
  tin: string | null;
  vat_registered: boolean;
  vat_number: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  timezone: string;
  accounting_basis: "accrual" | "cash";
  base_currency: string;
  calendar: "gregorian" | "ethiopian";
  fiscal_year_start_month: number;
  conversion_date: string | null;
  /** Nothing may post on or before this date. */
  books_closed_until: string | null;
  /** True once anything is posted: the fields in locked_fields can no longer change. */
  books_started: boolean;
  locked_fields: string[];
  fiscal_years: { starts_on: string; ends_on: string; status: string }[];
  /** Above these amounts someone other than the creator approves (invoices, bills, manual journals). */
  approval?: Partial<Record<"invoice_above" | "bill_above" | "journal_above", string>>;
  /** Payment reminders: emailed to the customer so many days after an invoice falls due (048). */
  reminders?: { enabled: boolean; days: number[] };
  /** Email reminders of tax returns due (054): on unless turned off. */
  tax_reminders?: boolean;
  /** Interest on late payment (079): an annual rate and the income account it goes to. */
  late_interest?: { rate: string | null; account_id: string | null };
  /** How invoices look on paper and as PDF files. */
  invoice_template?: { accent: string | null; payment_details: string | null; footer: string | null };
  can: { manage: boolean; close_periods: boolean; reopen_periods: boolean; enter_rates: boolean; manage_dimensions: boolean };
  options: { tax_id_label: string; calendars: string[]; legal_forms: string[]; income_accounts?: { id: string; code: string; name: string }[] };
}

/** GET /currencies: the base currency and the foreign currencies a company works in, with each one's latest rate. */
export interface Currencies {
  base_currency: string;
  data: { currency: string; is_enabled: boolean; latest_rate: string | null; latest_rate_date: string | null }[];
}

export interface Account {
  id: string;
  code: string;
  name: string;
  type: "asset" | "liability" | "equity" | "income" | "expense";
  subtype?: string | null;
  system_key: string | null;
  /** Set for a bank or wallet account kept in a foreign currency. */
  currency: string | null;
  is_active: boolean;
}

/** GET /opening-balances: the trial balance entered as at the conversion date. */
export interface OpeningBalances {
  conversion_date: string | null;
  base_currency: string;
  journal_entry: { id: string; number: number; entry_date: string } | null;
  lines: { account_id: string; debit: string; credit: string }[];
  /** Debits minus credits of the lines; booked to Opening balance equity and flagged. */
  difference: string;
  /** System keys of control accounts whose opening balance comes from their detail, not one figure. */
  from_detail: string[];
  /** Open invoices and bills brought in from the old books at conversion (057), not voided. */
  documents?: OpeningDocument[];
  can: { enter: boolean };
}

export interface OpeningDocument {
  id: string;
  type: "invoice" | "bill";
  number: string;
  /** A bill's supplier invoice number. */
  reference: string | null;
  contact: { id: string; name: string } | null;
  issue_date: string;
  due_date: string | null;
  currency: string;
  total: string;
  amount_due: string;
  status: string;
}

/** GET /numbering: how each type of document is numbered, and the number the next posted one would get. */
export interface Numbering {
  doc_type: string;
  mode: "automatic" | "manual";
  prefix: string;
  include_year: boolean;
  padding: number;
  next_number: string;
}

/** A location (branch, site), class (department) or project that transactions are tagged with. */
export interface Dimension {
  id: string;
  kind: "location" | "class" | "project";
  code: string;
  name: string;
  is_active: boolean;
}

export interface Address {
  line1?: string;
  line2?: string;
  city?: string;
  region?: string;
  postal_code?: string;
  country?: string;
}

/** A customer (Module 2). Amounts are decimal strings; open_balance is in the base currency. */
export interface Customer {
  id: string;
  name: string;
  tax_id: string | null;
  email: string | null;
  phone: string | null;
  website?: string | null;
  currency: string | null;
  payment_terms_days: number;
  credit_limit: string | null;
  tax_exempt?: boolean;
  tax_exempt_reason?: string | null;
  address?: Address;
  delivery_address?: Address;
  notes?: string | null;
  is_active: boolean;
  open_balance: string;
  recent_invoices?: { id: string; number: string | null; issue_date: string; due_date: string | null; currency: string; total: string; amount_due: string; status: string }[];
  can?: { create: boolean; edit: boolean };
}

export interface Contact {
  id: string;
  name: string;
  tax_id: string | null;
}

/** Module 5: a place the company keeps money, a ledger account of its own. */
export interface BankAccount {
  id: string;
  code: string;
  name: string;
  kind: "bank" | "mobile_money" | "cash" | "undeposited_funds";
  currency: string;
  is_active: boolean;
  bank_details: { bank_name?: string; account_number?: string; branch?: string; provider?: string; wallet_number?: string };
  balance: string;
  balance_base: string;
  /** A petty cash float, and what it takes to bring the box back up to it. */
  float_amount?: string | null;
  top_up?: string | null;
  can?: { manage: boolean; transfer: boolean; deposit: boolean };
}

/** Module 5: one count of a cash box, by a named person; a difference was posted to cash over and short. */
export interface CashCount {
  id: string;
  counted_on: string;
  counted_by: string;
  denominations: Record<string, number>;
  counted: string;
  book_balance: string;
  difference: string;
  entry: number | null;
  note: string | null;
  recorded_at: string;
}

export interface BankRegister {
  account: BankAccount;
  currency: string;
  from: string;
  to: string;
  opening: string;
  closing: string;
  lines: { date: string; entry: number; source_type: string; memo: string | null; description: string | null; contact: string | null; in: string; out: string; balance: string }[];
}

/** Module 5: one line of a bank, wallet or cash statement, and how it is explained in the books. */
export interface StatementLine {
  id: string;
  account_id: string;
  date: string;
  description: string;
  reference: string | null;
  amount: string;
  balance_after: string | null;
  status: "imported" | "matched" | "categorised" | "excluded" | "reconciled";
  note: string | null;
  source: string;
  entry: { id: string; number: number; date: string; memo: string | null; source_type: string; status: string } | null;
  suggestions?: { journal_line_id: string; entry: number; date: string; memo: string | null; description: string | null; contact: string | null; source_type: string; days_apart: number }[];
  /** 076: the bank rule that fits a line to review: where it would categorise it. */
  rule?: { rule_id: string; name: string; account: { id: string; code: string; name: string }; contact: { id: string; name: string } | null; memo: string | null } | null;
}

/** GET /reports/cash-forecast: from the cash held today, what is due in and out week by week. */
export interface CashForecast {
  as_of: string; weeks: number; opening: string; closing: string; goes_below_zero: boolean;
  overdue: { receivables: string; payables: string };
  lowest: { week: number; from: string; closing: string } | null;
  data: { week: number; from: string; to: string; receivables: string; payables: string; cheques_in: string; cheques_out: string; in: string; out: string; net: string; closing: string }[];
}

/** GET /reports/kpis: the home page's key figures, from the books as they stand. */
export interface Kpis {
  as_of: string; currency: string;
  cash: { now: string; month_ago: string; change: string };
  receivable: { balance: string; overdue: string; overdue_count: number };
  payable: { balance: string; due_this_week: string; due_this_week_count: number };
  month: { from: string; to: string; last_from: string; last_to: string; sales: string; sales_last: string | null; net_profit: string; net_profit_last: string | null };
}

/** A recurring invoice (077): an invoice repeated on a schedule. */
export interface RecurringInvoice {
  id: string; name: string; contact_id: string; customer: string; currency: string; frequency: "weekly" | "monthly" | "quarterly" | "yearly";
  next_on: string; ends_on: string | null; post: boolean; is_active: boolean; made_count: number; last_made_on: string | null; last_error: string | null;
  posts_as_name?: string | null; lines: number;
}

/** A bank rule (076): how a kind of statement line is categorised. */
export interface BankRule {
  id: string; name: string; bank_account_id: string | null; bank_account: string | null; direction: "in" | "out" | "any"; contains: string;
  amount_min: string | null; amount_max: string | null; target_account_id: string; target_code: string; target_name: string;
  contact_id: string | null; contact_name: string | null; memo: string | null; priority: number; is_active: boolean;
}

export interface StatementLines {
  account: { id: string; code: string; name: string; currency: string };
  status: StatementLine["status"] | "all";
  counts: Record<StatementLine["status"], number>;
  data: StatementLine[];
  can: { review: boolean };
}

/** Module 5: a bank statement agreed with the books. A draft carries its entries; a finished one its saved report. */
export interface ReconciliationSummary {
  id: string;
  account_id: string;
  status: "draft" | "completed" | "undone";
  statement_date: string;
  opening_balance: string;
  ending_balance: string;
  completed_at: string | null;
  undone_at: string | null;
  undo_reason: string | null;
}

export interface ReconciliationLine {
  journal_line_id?: string;
  date: string;
  entry: number;
  source_type: string;
  memo: string | null;
  contact: string | null;
  amount: string;
  on_statement: boolean;
  cleared?: boolean;
}

export interface Reconciliation extends ReconciliationSummary {
  account: { id: string; code: string; name: string; currency: string };
  can: { reconcile: boolean };
  /** A draft's entries up to the statement date; null once finished (see cleared and outstanding). */
  lines: ReconciliationLine[] | null;
  cleared_in?: string;
  cleared_out?: string;
  cleared_balance?: string;
  difference?: string;
  outstanding_total?: string;
  book_balance?: string;
  /** The saved report of a finished reconciliation. */
  opening?: string;
  ending?: string;
  cleared?: ReconciliationLine[];
  outstanding?: ReconciliationLine[];
}

export interface Reconciliations {
  account: { id: string; code: string; name: string; currency: string };
  data: ReconciliationSummary[];
  draft_id: string | null;
  reconciled_through: string | null;
  next_opening: string;
  can: { reconcile: boolean };
}

/** Module 5: a bank account's cheque register (cheques written or received), read from payments and the statement. */
export interface ChequeRegister {
  account: { id: string; code: string; name: string; currency: string };
  direction: "out" | "in";
  data: { id: string; kind: string; number: string | null; date: string; contact: string | null; currency: string; amount: string; reference: string | null;
    status: "issued" | "cleared" | "cancelled" | "received" | "bounced" | "reversed"; bounced_on: string | null }[];
  counts: Record<string, number>;
  next_number: string | null;
  can: { bounce: boolean };
}

/** Module 6: an account of the chart with its balance (debit minus credit) and where it sits. */
export interface ChartAccount {
  id: string;
  code: string;
  name: string;
  type: "asset" | "liability" | "equity" | "income" | "expense";
  kind: string | null;
  system_key: string | null;
  parent_id: string | null;
  currency: string | null;
  is_active: boolean;
  balance: string;
  has_entries: boolean;
  is_system: boolean;
  is_money: boolean;
  depth?: number;
}

/** Module 6: an entry of the books, as listed. */
export interface JournalRow {
  id: string;
  number: number;
  date: string;
  memo: string | null;
  source_type: string;
  source_id: string | null;
  status: "posted" | "reversed";
  currency: string;
  total: string;
  posted_by: string | null;
}

/** Module 6: one entry with its lines, where it came from and what reversed it. */
export interface JournalDetail {
  id: string;
  number: number;
  date: string;
  memo: string | null;
  source_type: string;
  source_label: string;
  status: "posted" | "reversed";
  currency: string;
  fx_rate: string;
  posted_by: string | null;
  posted_at: string;
  lines: { line_no: number; account_id: string; code: string; account: string; type: string; description: string | null; debit: string; credit: string;
    amount_txn: string; contact: string | null; location: string | null; class: string | null; project: string | null }[];
  total: string;
  document: { id: string; type: string; number: string | null } | null;
  payment: { id: string; direction: "in" | "out"; kind: string } | null;
  reversed_by: { id: string; number: number; date: string } | null;
  reverses: { id: string; number: number; date: string } | null;
  can: { reverse: boolean };
}

/** Module 6: a manual journal above the approval limit, waiting for (or decided by) someone other than the asker. */
export interface JournalRequest {
  id: string;
  date: string;
  memo: string;
  total: string;
  status: "pending" | "approved" | "rejected" | "withdrawn";
  requested_by: string | null;
  requested_at: string;
  decided_by: string | null;
  decided_at: string | null;
  note: string | null;
  journal_entry: { id: string; number: number } | null;
  reverse_on: string | null;
  lines: { account_id: string; code: string | null; account: string | null; debit: string | null; credit: string | null; description: string | null }[] | null;
  can: { approve: boolean; withdraw: boolean };
}

/** Module 9c: a fixed asset on the register. */
export interface FixedAsset {
  id: string;
  code: string;
  name: string;
  category: string | null;
  status: "active" | "disposed";
  location: { id: string; name: string } | null;
  custodian: string | null;
  asset_account: { id: string; code: string; name: string };
  depreciation_account_id: string;
  expense_account_id: string;
  acquired_on: string;
  cost: string;
  residual_value: string;
  method: "straight_line" | "reducing_balance";
  useful_life_months: number | null;
  annual_rate: string | null;
  depreciation_starts_on: string;
  opening_depreciation: string;
  /** How tax depreciates it, when the tax law differs from the books (061); method null: as the books. */
  tax?: { method: "straight_line" | "reducing_balance" | null; useful_life_months: number | null; annual_rate: string | null; opening_depreciation: string };
  disposal: { date: string; proceeds: string; entry_id: string } | null;
  depreciated?: string;
  book_value?: string;
}

/** GET fixed-assets/tax-depreciation (061): a fiscal year's tax schedule beside the books. */
export interface TaxDepreciationYear {
  from: string;
  to: string;
  rows: {
    id: string; code: string; name: string; category: string | null; acquired_on: string; cost: string; disposed_on: string | null;
    tax_rule: { own: boolean; method: string; useful_life_months: number | null; annual_rate: string | null };
    tax_opening: string; tax_additions: string; tax_depreciation: string; tax_closing: string;
    book_opening: string; book_depreciation: string; book_closing: string; difference: string;
  }[];
  totals: Record<"tax_opening" | "tax_additions" | "tax_depreciation" | "tax_closing" | "book_opening" | "book_depreciation" | "book_closing" | "difference", string>;
}

export interface FixedAssetDetail extends FixedAsset {
  posted: { period_end: string; amount: string; entry_id: string }[];
  ahead: { period_end: string; amount: string }[];
  depreciated: string;
  book_value: string;
  depreciated_by_runs: boolean;
  can: { manage: boolean };
}

export interface FixedAssetRegister {
  data: (FixedAsset & { depreciated: string; book_value: string })[];
  runs: { id: string; period_end: string; total: string; entry: { id: string; number: number } }[];
  next_period_end: string;
  can: { manage: boolean; depreciate: boolean };
}

/** Module 9a: a project, with its income, costs and profit from the ledger. */
export interface Project {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  customer: { id: string; name: string } | null;
  status: "active" | "on_hold" | "completed" | "cancelled";
  billing: "fixed_fee" | "time_and_materials" | "non_billable";
  starts_on: string | null;
  ends_on: string | null;
  contract_value: string | null;
  budget_income: string | null;
  budget_cost: string | null;
  hourly_rate: string | null;
  notes: string | null;
  income: string;
  costs: string;
  profit: string;
}

export interface ProjectDetail extends Project {
  from: string | null;
  to: string | null;
  time: { logged_hours: string; unbilled_hours: string; unbilled_value: string; unpriced: boolean };
  accounts: { account_id: string; code: string; name: string; type: string; amount: string }[];
  documents: { id: string; type: string; number: string | null; date: string; contact: string; currency: string; amount: string }[];
  /** Milestones of a fixed-fee project, billed one by one (049). */
  milestones?: ProjectMilestone[];
  can: { manage: boolean };
}

export interface ProjectMilestone {
  id: string;
  name: string;
  amount: string;
  due_on: string | null;
  position: number;
  /** planned (not billed, or its invoice voided), drafted (on a draft invoice), invoiced (posted, owed) or paid. */
  status: "planned" | "drafted" | "invoiced" | "paid";
  invoice: { id: string; number: string | null; status: string } | null;
}

/** Module 9a: hours logged against a project. */
export interface TimeEntry {
  id: string;
  project: { id: string; code: string; name: string };
  user: { id: string; name: string };
  work_date: string;
  hours: string;
  billable: boolean;
  rate: string | null;
  note: string | null;
  invoice: { id: string; number: string | null; status: string } | null;
}

/** Module 7: a tax worksheet, built from the ledger (VAT: output and input; withholding: what was withheld). */
/** tot: turnover tax, where the company has TOT rates (052). */
export type TaxKind = "vat" | "withholding" | "tot";

export interface TaxRateRow { code: string; name: string; rate: string | null; kind: string | null; taxable: string; tax: string }
export interface TaxDocumentRow { id: string; type: string; number: string | null; date: string; contact: string; tax_id: string | null; taxable: string; tax: string }

export interface TaxWorksheet {
  kind: TaxKind;
  from: string;
  to: string;
  currency: string;
  output_tax: string;
  input_tax: string;
  net: string;
  sales?: { rates: TaxRateRow[]; documents: TaxDocumentRow[]; taxable: string; tax: string };
  purchases?: { rates: TaxRateRow[]; documents: TaxDocumentRow[]; taxable: string; tax: string };
  withheld?: TaxWithholdingRegister;
  withheld_by_customers?: TaxWithholdingRegister;
  other: { output: string; input: string; entries: { id: string; number: number; date: string; memo: string | null; source_type: string; output: string; input: string }[] };
  filed?: { id: string; period_start: string; period_end: string; filed_on: string }[];
  can?: { file: boolean };
}

export type FilingStatus = "paid" | "filed" | "overdue" | "due_soon" | "due" | "open";

export interface TaxCalendar {
  today: string;
  overdue: number;
  due_soon: number;
  kinds: Partial<Record<TaxKind, { note: string | null; periods: { period_start: string; period_end: string; due_on: string; status: FilingStatus;
    return: { id: string; filed_on: string; settled_on: string | null } | null }[] }>>;
}

export interface TaxWithholdingRegister {
  payments: { id: string; date: string; contact: string | null; tax_id: string | null; reference: string | null; certificate: string | null; gross: string; withheld: string }[];
  gross: string;
  tax: string;
}

export interface TaxReturn {
  id: string;
  kind: TaxKind;
  period_start: string;
  period_end: string;
  filed_on: string;
  reference: string | null;
  output_tax: string;
  input_tax: string;
  net: string;
  filed_by: string | null;
  settled_on: string | null;
  figures?: TaxWorksheet;
  settlement?: { id: string; number: number; date: string } | null;
  can?: { settle: boolean };
}

/** Module 6, accountant tools: an entry the review queue flags, and its review once marked. */
export type ReviewFlag = "manual_cash" | "round_amount" | "large" | "backdated" | "out_of_hours" | "opening_equity";

export interface ReviewQueue {
  from: string;
  to: string;
  data: { id: string; number: number; date: string; memo: string | null; source_type: string; status: string; total: string; posted_by: string | null;
    posted_at: string; flags: ReviewFlag[]; review: { by: string | null; at: string; note: string | null } | null }[];
  counts: Record<ReviewFlag, number>;
  flags: Record<ReviewFlag, string>;
  truncated: boolean;
  can: { review: boolean };
}

/** Module 6: a manual journal posted on a schedule. Paused, it says why in last_error. */
export interface RecurringJournal {
  id: string;
  name: string;
  memo: string;
  total: string;
  frequency: "monthly" | "quarterly" | "yearly";
  next_on: string;
  ends_on: string | null;
  is_active: boolean;
  posted_count: number;
  last_posted_on: string | null;
  last_error: string | null;
  posts_as: string | null;
  lines: { account_id: string; code: string | null; account: string | null; debit: string | null; credit: string | null; description: string | null;
    location_id: string | null; class_id: string | null; project_id: string | null }[] | null;
  entries: { id: string; number: number; date: string; status: string }[] | null;
  can: { edit: boolean };
}

/** Module 4: something the company sells or buys. Stock items have a bin card per warehouse. */
export interface Item {
  id: string;
  sku: string | null;
  name: string;
  type: "inventory" | "non_inventory" | "service" | "bundle";
  unit: string;
  sale_price: string | null;
  purchase_price: string | null;
  income_account_id: string | null;
  expense_account_id: string | null;
  reorder_point: string | null;
  /** Weighted average (default) or FIFO, chosen before the item's first stock move (064). */
  costing?: "average" | "fifo";
  /** 074: tracked by batch (with expiry), chosen before the first stock move. */
  tracking?: "none" | "batch";
  /** 075: what a suggested order brings it back up to, and who it goes to. */
  max_level?: string | null;
  preferred_supplier_id?: string | null;
  /** 074: its batches with what is on hand in each warehouse, the first to expire first. */
  batches?: { id: string; number: string; expires_on: string | null; warehouse: string | null; quantity: string | null }[] | null;
  is_active: boolean;
  on_hand?: string;
  /** What the confirmed sales orders still want of a stock item, and on hand less that (067; below zero when short). */
  reserved?: string;
  available?: string | null;
  /** Still to come on issued purchase orders (068). */
  on_order?: string;
  value?: string;
  balances?: { warehouse: { id: string; name: string }; quantity: string; value: string }[];
  /** 072: other units it is bought or sold in, each a fixed number of its own (archived ones on the item's own page only). */
  units?: { id: string; name: string; factor: string; is_active?: boolean }[];
  /** 073: a bundle's parts, each with its quantity per bundle in its own unit. */
  components?: { item_id: string; name: string; type: string; unit: string; quantity: string }[] | null;
  can?: { edit: boolean; adjust: boolean; components?: boolean };
}

export interface Warehouse { id: string; name: string; is_active: boolean }

/** Module 4/10: per stock item, on hand at the start, in, out and on hand at the end of a period. */
export interface StockMovement {
  from: string;
  to: string;
  warehouse: { id: string; name: string } | null;
  currency: string;
  rows: {
    item: { id: string; sku: string | null; name: string; unit: string };
    opening_qty: string; opening_value: string; in_qty: string; in_value: string; out_qty: string; out_value: string; sold_qty: string;
    closing_qty: string; closing_value: string;
  }[];
  totals: { opening_value: string; in_value: string; out_value: string; closing_value: string };
}

export type StockBand = "0-30" | "31-90" | "91-180" | "over-180" | "never";

/** Module 4/10: what is on hand at a date, by days since it last sold. */
export interface StockAgeing {
  as_of: string;
  warehouse: { id: string; name: string } | null;
  currency: string;
  rows: {
    item: { id: string; sku: string | null; name: string; unit: string };
    quantity: string; value: string; last_sold_on: string | null; last_received_on: string | null; days_since_sale: number | null; band: StockBand;
  }[];
  bands: { band: StockBand; items: number; value: string }[];
  total_value: string;
}

/** GET /stock: what is on hand per item and warehouse, valued at weighted average. */
export interface StockOnHand {
  data: { item: { id: string; sku: string | null; name: string; unit: string; reorder_point: string | null }; warehouse: { id: string; name: string }; quantity: string; value: string; average_cost: string }[];
  total_value: string;
}

/** GET /reports/bin-card. */
export interface BinCard {
  opening: { quantity: string; value: string };
  moves: { moved_on: string; kind: string; reference: string | null; qty_in: string; qty_out: string; unit_cost: string; value: string; balance_qty: string; balance_value: string }[];
}

/** Where a supplier is paid: a bank account, a mobile-money wallet, or both. */
export interface Payout {
  bank_name?: string;
  account_name?: string;
  account_number?: string;
  mobile_money_provider?: string;
  mobile_money_number?: string;
}

/** Module 3: a contact the company buys from (contacts.is_vendor). */
export interface Supplier {
  id: string;
  name: string;
  tax_id: string | null;
  email: string | null;
  phone: string | null;
  website?: string | null;
  currency: string | null;
  payment_terms_days: number;
  address?: Address;
  payout?: Payout;
  default_expense_account_id: string | null;
  withholding_exempt?: boolean;
  withholding_exempt_reason?: string | null;
  notes?: string | null;
  is_active: boolean;
  is_customer?: boolean;
  open_balance: string;
  recent_bills?: { id: string; number: string | null; reference: string | null; issue_date: string; due_date: string | null; currency: string; total: string; amount_due: string; status: string }[];
  can?: { create: boolean; edit: boolean; bill?: boolean };
}

export interface InvoiceLine {
  id?: string;
  line_no: number;
  description: string;
  quantity: string;
  unit_price: string;
  discount_rate: string;
  tax_rate_id: string | null;
  account_id: string;
  /** The item sold on the line, if any. */
  item_id?: string | null;
  /** The project the line is tagged with (job costing), if any. */
  project_id?: string | null;
  /** On an invoice made from a sales order: the order line it delivers (067). */
  source_line_id?: string | null;
  /** 072: another unit of the item the line is in (its quantity and price are in it), kept as it was then. */
  unit_id?: string | null;
  unit_name?: string | null;
  unit_factor?: string;
  /** 074: the batch a bill line brings in, or a credit-note line brings back. */
  batch_number?: string | null;
  batch_expires_on?: string | null;
  line_subtotal: string;
  line_tax: string;
}

export interface EInvoiceSubmission {
  authority: string;
  status: "queued" | "submitted" | "cleared" | "rejected" | "failed";
  clearance_number: string | null;
  qr_payload: string | null;
  last_error: string | null;
}

export interface Invoice {
  id: string;
  /** null for a draft while numbering is automatic: the number is given when the invoice is posted. */
  number: string | null;
  /** Invoice state machine: draft → awaiting_approval → posted → part_paid → paid; void. */
  status: "draft" | "awaiting_approval" | "posted" | "part_paid" | "paid" | "void";
  /** Send back on save: the API refuses the save (409) if someone else changed the invoice first. */
  lock_version?: number;
  contact: Contact;
  issue_date: string;
  due_date: string | null;
  currency: string;
  subtotal: string;
  tax_total: string;
  total: string;
  amount_due: string;
  lines?: InvoiceLine[];
  einvoice_submissions?: EInvoiceSubmission[];
}

export interface TaxRate {
  id: string;
  code: string;
  name: string;
  kind: "vat" | "sales_tax" | "tot" | "withholding" | "excise" | "other";
  /** 0.15 = 15% */
  rate: string;
  effective_from: string;
  effective_to: string | null;
}

/** GET /invoices/{id}: the invoice, warnings that do not block, and what the signed-in member may do with it. */
/** A supplier's bill (Module 3): posted to accounts payable; `reference` is the supplier's own invoice number. */
export interface Bill extends Invoice {
  reference: string | null;
}

export interface BillDetail extends Bill {
  /** A supplier credit (vendor_credit) is read and approved through the bill routes, like a credit note through invoices. */
  type: "bill" | "vendor_credit";
  fx_rate: string;
  notes: string | null;
  contact: Contact & { email?: string | null; payout?: Payout };
  lines: InvoiceLine[];
  original?: { id: string; number: string | null; reference: string | null } | null;
  warnings: { type: string; message: string; bill_id?: string }[];
  /** 069: against its purchase order and the goods received, when it was made from an order. */
  match?: ThreeWayMatch | null;
  can: { edit: boolean; approve: boolean; void: boolean; credit?: boolean; apply?: boolean; refund?: boolean };
}

/** An expense paid on the spot (Module 3): posted at once against the account it was paid from. */
export interface Expense extends Bill {
  type: "expense";
  payment_method: string;
  paid_from?: { id: string; code: string; name: string };
  fx_rate: string;
  notes: string | null;
  lines?: InvoiceLine[];
  can?: { void: boolean };
}

/** A cash sale (070): a sale paid at once, posted at once into the money account it went into; nothing is owed. */
export interface SalesReceipt extends Invoice {
  type: "sales_receipt";
  journal_entry_id: string | null;
  reference: string | null;
  payment_method: string;
  paid_from?: { id: string; code: string; name: string };
  fx_rate: string;
  notes: string | null;
  lines?: InvoiceLine[];
  can?: { void: boolean };
}

export interface InvoiceDetail extends Invoice {
  type: "invoice" | "credit_note";
  prices_include_tax?: boolean;
  /** Where stock lines leave from (025). */
  warehouse_id?: string | null;
  /** A credit note names the invoice it corrects. */
  original_document_id: string | null;
  original?: { id: string; number: string | null } | null;
  /** The member who made the sale; a credit note keeps its invoice's. */
  salesperson_id?: string | null;
  salesperson?: { id: string; name: string } | null;
  /** The quote or sales order it was made from (056). */
  source?: { id: string; type: string; number: string | null } | null;
  /** A credit note whose goods came back into stock (058). */
  restock?: boolean;
  fx_rate: string;
  notes: string | null;
  contact: Contact & { email?: string | null };
  lines: InvoiceLine[];
  warnings: { type: string; message: string }[];
  can: { edit: boolean; approve: boolean; void: boolean; credit: boolean; apply: boolean; refund?: boolean; remind?: boolean; email?: boolean; retain?: boolean };
  /** Retention the customer holds back until the work is complete (050). */
  retentions?: InvoiceRetention[];
  /** Payment reminders sent to the customer, newest first (048). */
  reminders?: InvoiceReminderSent[];
}

/** GET post-dated-cheques (062): the register, what is held by side and currency, and what the member may do. */
export interface PostDatedCheques {
  data: {
    id: string; direction: "in" | "out"; contact: { id: string; name: string }; account: { id: string; code: string; name: string };
    cheque_number: string; drawn_on: string | null; cheque_date: string; handed_on: string; currency: string; amount: string; note: string | null;
    status: "held" | "presented" | "cancelled"; payment_id: string | null; closed_on: string | null; due: boolean;
  }[];
  held: { direction: "in" | "out"; currency: string; cheques: number; amount: string }[];
  can: { receive: boolean; pay: boolean };
}

/** GET bills/{bill}/landed-costs (060): what a cost bill was spread onto, what a goods bill received, and where it can go. */
export interface LandedCosts {
  spread: LandedCostRow[];
  received: LandedCostRow[];
  /** What is left of the cost bill to spread, in the base currency. */
  left: string;
  candidates: { id: string; number: string; reference: string | null; issue_date: string; total: string; currency: string; supplier: string | null }[];
  can: { apply: boolean };
}

export interface LandedCostRow {
  id: string;
  amount: string;
  method: "value" | "quantity";
  applied_on: string;
  journal_entry_id: string;
  cost_bill_id: string;
  cost_bill_number: string;
  goods_bill_id: string;
  goods_bill_number: string;
}

/** Quotes and sales orders (056): sales documents that post nothing, converted into a sales order or an invoice. */
export type QuoteKind = "quote" | "sales_order";
export type QuoteStatus = "draft" | "sent" | "accepted" | "declined" | "expired" | "converted" | "void";

export interface QuoteSummary extends Omit<Invoice, "status"> {
  type: QuoteKind;
  status: QuoteStatus;
}

export interface QuoteDetail extends Omit<InvoiceDetail, "type" | "status" | "can" | "warnings" | "original" | "original_document_id" | "retentions" | "reminders"> {
  type: QuoteKind;
  status: QuoteStatus;
  /** The quote a sales order was made from. */
  source?: { id: string; type: string; number: string | null } | null;
  /** The sales order or invoice made from it. */
  made?: { id: string; type: string; number: string | null; status: string } | null;
  /** Earlier versions kept when it was revised after it went to the customer (or was confirmed). */
  versions: number;
  /** A sales order's lines (by line id): ordered, delivered by its live invoices, and left to deliver (067). */
  /** 071: with what left on delivery notes (shipped), and what can be invoiced now (billable). */
  delivery?: Record<string, { ordered: string; delivered: string; remaining: string; shipped?: string; billable?: string }> | null;
  /** The invoices made from a sales order, the earliest first. */
  invoices?: { id: string; number: string | null; issue_date: string; status: string; total: string }[] | null;
  /** 071: the order's delivery notes, and its stock lines that can still leave (by line id). */
  delivery_notes?: { id: string; number: string; delivered_on: string; reference: string | null; status: "posted" | "void"; warehouse: string }[] | null;
  leaving?: Record<string, string> | null;
  can: { edit: boolean; send: boolean; accept: boolean; decline: boolean; extend: boolean; cancel: boolean; convert: boolean; email: boolean; close?: boolean; deliver?: boolean };
}

/** A delivery note (071): what of a confirmed sales order left, out of stock at its cost, the cost of goods sold recorded then. */
export interface DeliveryNote {
  id: string; number: string; delivered_on: string; reference: string | null; note: string | null; status: "posted" | "void";
  journal_entry_id: string | null; void_reason: string | null; voided_at: string | null; created_at: string;
  sales_order_id: string; order_number: string | null; contact_id: string; customer: string; warehouse_id: string; warehouse: string; cost: string;
  lines: { id: string; order_line_id: string; line_no: number; description: string; item_id: string; item_name: string; unit: string | null; quantity: string }[];
  can: { void: boolean };
}
export interface DeliveryNoteSummary {
  id: string; number: string; delivered_on: string; reference: string | null; status: "posted" | "void";
  sales_order_id: string; order_number: string | null; customer: string; warehouse: string;
}
/** GET /delivery-notes/uninvoiced: goods that left and are not yet on an invoice, line by line. */
export interface UninvoicedGoods {
  data: { order_id: string; number: string | null; contact_id: string; customer: string; line_id: string; line_no: number; description: string;
    item_id: string | null; item_name: string | null; unit: string | null; last_delivered: string; unit_price: string; delivered: string; invoiced: string; open: string }[];
}

/** GET /sales-orders/back-orders: what confirmed orders still owe, and the stock their items have (067). */
export interface BackOrderLine {
  order_id: string;
  number: string | null;
  issue_date: string;
  due_date: string | null;
  contact_id: string;
  customer: string;
  line_id: string;
  line_no: number;
  description: string;
  item_id: string | null;
  sku: string | null;
  item_name: string | null;
  item_type: string | null;
  ordered: string;
  delivered: string;
  remaining: string;
}
export interface BackOrders {
  data: BackOrderLine[];
  stock: Record<string, { on_hand: string; reserved: string; available: string }>;
}

/** A purchase order (068): issued to a supplier, billed in parts as their invoices come in; it posts nothing. */
export type PurchaseOrderStatus = "draft" | "accepted" | "converted" | "void";
export interface PurchaseOrderSummary extends Omit<Invoice, "status"> {
  type: "purchase_order";
  status: PurchaseOrderStatus;
}
export interface PurchaseOrderDetail extends Omit<InvoiceDetail, "type" | "status" | "can" | "warnings" | "original" | "original_document_id" | "retentions" | "reminders"> {
  type: "purchase_order";
  status: PurchaseOrderStatus;
  /** Each order line (by line id): ordered, billed by its live bills, still to come; received on goods received notes and what can be billed now (069). */
  delivery: Record<string, { ordered: string; delivered: string; remaining: string; received: string; billable: string }>;
  bills: { id: string; number: string | null; reference: string | null; issue_date: string; status: string; total: string }[];
  /** 069: the goods received notes of the order, and the stock lines that can still arrive (by line id). */
  receipts: { id: string; number: string; received_on: string; reference: string | null; status: "posted" | "void"; warehouse: string; value: string | null }[];
  arriving: Record<string, string>;
  versions: number;
  can: { edit: boolean; issue: boolean; bill: boolean; receive: boolean; close: boolean; cancel: boolean };
}

/** A goods received note (069): what of an issued purchase order arrived, into stock at the order's cost against goods received not invoiced. */
export interface GoodsReceiptLine {
  id: string; order_line_id: string; line_no: number; description: string; item_id: string; item_name: string; unit: string | null; quantity: string; amount: string;
}
export interface GoodsReceipt {
  id: string; number: string; received_on: string; reference: string | null; note: string | null; status: "posted" | "void";
  journal_entry_id: string | null; void_reason: string | null; voided_at: string | null; created_at: string;
  purchase_order_id: string; order_number: string | null; contact_id: string; supplier: string; warehouse_id: string; warehouse: string;
  lines: GoodsReceiptLine[];
  can: { void: boolean };
}
export interface GoodsReceiptSummary {
  id: string; number: string; received_on: string; reference: string | null; status: "posted" | "void";
  purchase_order_id: string; order_number: string | null; supplier: string; warehouse: string; value: string | null;
}
/** GET /goods-receipts/unbilled: goods received not invoiced, line by line; the values add up to the GRNI account. */
export interface UnbilledGoods {
  data: { order_id: string; number: string | null; contact_id: string; supplier: string; line_id: string; line_no: number; description: string;
    item_id: string | null; item_name: string | null; unit: string | null; last_received: string; received: string; billed: string; open: string; value: string }[];
  total: string;
}
/** A bill made from a purchase order, line by line against the order and the goods received (069): warnings, never a block. */
export interface ThreeWayMatch {
  order_id: string;
  order_number: string | null;
  lines: { line_id: string; line_no: number; description: string; ordered: string; received: string | null; billed_elsewhere: string; quantity: string;
    order_price: string; bill_price: string; price_change: string | null; by_receipt: boolean; price_differs: boolean; over: boolean }[];
  warnings: string[];
}

export const quotePath = (kind: QuoteKind) => (kind === "quote" ? "quotes" : "sales-orders");

/** GET /payments-made/withholding/{supplier}: what the country pack says about withholding on a payment to them. */
export interface WithholdingAdvice {
  applies: boolean;
  rate: string | null;
  code: string | null;
  goods_above: string | null;
  services_above: string | null;
  note: string;
  cash_limit: string | null;
}

/** A payment received (Module 2). Recorded once; reversed, never changed. */
export interface PaymentReceived {
  id: string;
  contact_id: string;
  contact?: { id: string; name: string };
  account_id: string;
  payment_date: string;
  method: string;
  reference: string | null;
  currency: string;
  fx_rate: string;
  amount: string;
  fee: string;
  withholding: string;
  status: "posted" | "reversed";
  /** A cheque's number (029); the day it bounced, for a cheque received that did. */
  cheque_number?: string | null;
  bounced_on?: string | null;
  allocations_count?: number;
  allocations?: { id: string; amount: string; document_id: string; document?: { id: string; number: string | null; issue_date?: string; total: string; amount_due: string; status: string } }[];
}

/** GET /reports/ar-ageing: open invoices by days overdue; each total is the customer's receivable balance. */
export interface ArAgeing {
  as_of: string;
  rows: ({ contact_id: string; name: string } & Record<"current" | "days_1_30" | "days_31_60" | "days_61_90" | "over_90" | "credits" | "total", string>)[];
  totals: Record<"current" | "days_1_30" | "days_31_60" | "days_61_90" | "over_90" | "credits" | "total", string>;
}

/** GET /customers/{id}/statement: the receivable account's lines for the customer, with a running balance. */
export interface CustomerStatement {
  customer: { id: string; name: string; tax_id: string | null; address?: Address };
  currency: string;
  from: string;
  to: string;
  opening: string;
  closing: string;
  lines: { date: string; entry: number; source_type: string; memo: string | null; reference: string | null; document_id: string | null; debit: string; credit: string; balance: string }[];
}

/** Module 6: the general ledger for a period, account by account (amounts are debit minus credit, base currency). */
export interface GeneralLedger {
  from: string;
  to: string;
  currency: string;
  truncated: boolean;
  accounts: { account_id: string; code: string; name: string; type: string; opening: string; debits: string; credits: string; closing: string;
    lines: { line_id: string; date: string; entry_id: string; entry: number; source_type: string; status: string; memo: string | null; description: string | null; contact: string | null; debit: string; credit: string; balance: string }[] }[];
}

export interface TrialBalanceRow {
  account_id: string;
  code: string;
  name: string;
  type: string;
  debit: string;
  credit: string;
}

/** Module 6: what to check before closing the books through a date. */
export interface CloseChecklist {
  period_end: string;
  warnings: number;
  books_closed_until: string | null;
  items: { key: string; label: string; status: "ok" | "warn"; detail: string; link?: string; items: { id: string | null; label: string; note: string; link: string }[] }[];
  can: { reopen: boolean };
}

/** Module 6: a month-end revaluation of foreign balances, previewed or posted. */
export interface RevaluationLine {
  kind: "account" | "invoice" | "credit_note" | "bill" | "vendor_credit";
  label: string;
  currency: string;
  amount: string;
  booked: string;
  rate: string;
  restated: string;
  difference: string;
  document_id: string | null;
  account_id: string;
}

export interface RevaluationPreview {
  as_of: string;
  rates: Record<string, string>;
  lines: RevaluationLine[];
  net: string;
}

export interface RevaluationRun extends RevaluationPreview {
  id: string;
  by: string | null;
  entry: { id: string; number: number };
  reversal: { id: string; number: number; date: string };
}

/** Module 6: a fiscal year and what can be done with it now. */
export interface FiscalYear {
  id: string;
  starts_on: string;
  ends_on: string;
  status: "open" | "closing" | "closed" | "reopened";
  closed_by: string | null;
  closed_at: string | null;
  can_close: boolean;
  can_reopen: boolean;
  /** The adjustments period for the auditor: adjustments posted into the closed year, and when its audit was signed off. */
  adjustments: number;
  adjustments_closed_at: string | null;
  signed_off_by: string | null;
  can_adjust: boolean;
}

/** The auditor's worksheet for a fiscal year: balances before the close, the audit adjustments, and after (debit positive). */
export interface AdjustedTrialBalance {
  year: { id: string; starts_on: string; ends_on: string; status: FiscalYear["status"]; adjustments_closed_at: string | null };
  lines: { account_id: string; code: string; name: string; type: string; unadjusted: string; adjustments: string; adjusted: string }[];
  totals: { unadjusted: string; adjustments: string; adjusted: string };
  adjustments: { id: string; number: number; memo: string | null; posted_by: string | null; posted_at: string }[];
  can: { adjust: boolean };
}

export interface ProfitAndLossRow {
  code: string;
  name: string;
  current: string;
  previous: string | null;
}

export interface ProfitAndLoss {
  /** Accrual (when invoiced or billed) or cash (when the money moved). */
  basis?: "accrual" | "cash";
  sections: { income: ProfitAndLossRow[]; cost_of_sales: ProfitAndLossRow[]; expenses: ProfitAndLossRow[] };
  totals: Record<"income" | "cost_of_sales" | "gross_profit" | "expenses" | "net_profit", { current: string; previous: string | null }>;
}

/** Module 10: the balance sheet at a date, with the accounting equation checked. */
export interface BalanceSheet {
  sections: Record<"assets" | "liabilities" | "equity", { code: string; name: string; amount: string }[]>;
  equation: { assets: string; liabilities: string; equity: string; profit_to_date: string; liabilities_and_equity: string; difference: string; balanced: boolean };
}

export interface CashFlowStatement {
  from: string;
  to: string;
  currency: string;
  net_profit: string;
  sections: Record<"operating" | "investing" | "financing", { code: string; name: string; amount: string }[]>;
  totals: Record<"operating" | "investing" | "financing", string>;
  net_change: string;
  opening_cash: string;
  closing_cash: string;
  cash_change: string;
  balanced: boolean;
}

export interface ChangesInEquity {
  from: string;
  to: string;
  currency: string;
  lines: { code: string | null; name: string; opening: string; movement: string; closing: string }[];
  net_profit: string;
  opening: string;
  movement: string;
  closing: string;
}

export interface FinancialHealth {
  from: string;
  to: string;
  days: number;
  currency: string;
  figures: Record<"current_assets" | "current_liabilities" | "inventory" | "cash" | "receivables" | "payables" | "revenue" | "gross_profit" | "net_profit" | "credit_sales" | "purchases", string>
    & { monthly_cash_outflow: string | null };
  ratios: Record<"current_ratio" | "quick_ratio" | "cash_runway_months" | "dso_days" | "dpo_days" | "gross_margin" | "net_margin", string | null>;
}

/** Module 10: profit by location, class or project. */
export interface ProfitByDimension {
  kind: "location" | "class" | "project";
  from: string;
  to: string;
  currency: string;
  rows: { id: string | null; code: string | null; name: string; income: string; cost_of_sales: string; expenses: string; profit: string; margin: string | null }[];
  totals: Record<"income" | "cost_of_sales" | "expenses" | "profit", string>;
}

/** Module 10: sales or purchases by customer or supplier, item, or month. */
export interface DocumentAnalysis {
  side: "sales" | "purchases";
  group: "contact" | "item" | "month";
  from: string;
  to: string;
  currency: string;
  rows: { key: string | null; label: string; amount: string; tax: string; documents: number; quantity: string | null; share: string | null }[];
  totals: Record<"amount" | "tax", string>;
}

/** Module 10/11: the audit log, newest first. */
export interface AuditEntry {
  id: number;
  at: string;
  user: { id: string; name: string } | null;
  actor_type: string;
  action: string;
  subject_type: string;
  subject_id: string | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  ip: string | null;
  device: string | null;
}

export interface AuditLog {
  data: AuditEntry[];
  more: boolean;
  next_before_id: number | null;
  actions: string[];
  subject_types: string[];
  people: { id: string; name: string }[];
}

/** Module 10: a report saved by the person signed in, with the dates its period covers today. */
export interface SavedReport {
  id: string;
  name: string;
  report: string;
  period: string;
  period_label: string;
  from_date: string | null;
  to_date: string | null;
  options: Record<string, string>;
  schedule: "none" | "weekly" | "monthly";
  next_send_on: string | null;
  last_sent_at: string | null;
  covers: { from?: string; to?: string; as_of?: string };
}

/** Module 11: a person in the company, invited, active or deactivated. */
export interface Member {
  id: string;
  user: { id: string; name: string; email: string };
  role: string;
  /** A custom role's own permissions (with company.view); empty for every other role. */
  permissions: string[];
  status: "invited" | "active" | "deactivated";
  invited_at: string | null;
  joined_at: string;
  me?: boolean;
}

/** Module 10: the custom report (rows by one grouping, columns by period). */
export interface CustomReport {
  from: string; to: string; currency: string;
  columns: string[];
  rows: { key: string | null; code: string | null; label: string; values: string[]; total: string }[];
  totals: string[];
  total: string;
}

export interface InvoiceRetention { id: string; amount: string; held_on: string; released_on: string | null }
/** GET /invoices/{id}/write-offs: bad debts written off this invoice, and whether this role may write one off (078). */
export interface InvoiceWriteOff { id: string; amount: string; written_off_on: string; reason: string; reversed_on: string | null; reversal_reason: string | null }
/** GET /invoices/{id}/interest: what charging interest on late payment would make, and what was charged before (079). */
export interface LateInterestPreview {
  rate: string | null; account_id: string | null; owed: string; currency: string; from: string | null; through: string; days: number; amount: string;
  charged: { id: string; number: string | null; status: string; from: string; through: string; total: string }[];
  can: { charge: boolean };
}
export interface InvoiceWriteOffs { data: InvoiceWriteOff[]; can: { write_off: boolean } }

export interface InvoiceReminderSent { kind: "manual" | "scheduled"; step: number | null; sent_to: string; sent_at: string }

export interface Invitation { company_name: string; role: string; email: string; name: string; new_account: boolean; expires_at: string }

export class ApiError extends Error {
  constructor(public status: number, message: string, public body?: unknown) {
    super(message);
  }
}

interface RequestOptions {
  token?: string;
  companyId?: string;
  method?: "GET" | "POST" | "PUT" | "DELETE";
  body?: unknown;
  query?: Record<string, string | undefined>;
}

/**
 * Read at call time, not when the module loads: the production image is built once and pointed at an API by
 * its runtime environment. A module-level constant would freeze the build machine's (empty) value.
 */
export const apiBaseUrl = (): string | undefined => process.env.LEDGERLY_API_URL ?? process.env.NEXT_PUBLIC_LEDGERLY_API_URL;
export const isDemoMode = (): boolean => !apiBaseUrl();

export async function api<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const base = apiBaseUrl();
  if (!base) throw new ApiError(0, "LEDGERLY_API_URL is not set (demo mode)");

  const url = new URL(`/api/v1/${path.replace(/^\//, "")}`, base);
  for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined) url.searchParams.set(k, v);

  // Loaded here, not at the top: this module is also imported by tests and for its types.
  const visitor = await import("./visitor").then((m) => m.visitorHeaders()).catch(() => ({}));

  const res = await fetch(url, {
    method: opts.method ?? "GET",
    headers: {
      Accept: "application/json",
      ...visitor,
      ...(opts.body ? { "Content-Type": "application/json" } : {}),
      ...(opts.token ? { Authorization: `Bearer ${opts.token}` } : {}),
      ...(opts.companyId ? { "X-Company-Id": opts.companyId } : {}),
    },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    cache: "no-store",
  });

  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, (body as { message?: string } | null)?.message ?? res.statusText, body);
  return body as T;
}

export type { SignedInSession } from "./sessions";
export type { Grantable } from "./team";
export type { Subscription } from "./subscription";
import type { ImportResult } from "./imports";
import type { Subscription } from "./subscription";
import type { Grantable } from "./team";
import type { SignedInSession } from "./sessions";

/** A file from the API (not JSON), passed on as it came; an error answer becomes an ApiError as in api(). */
export async function apiFile(path: string, opts: { token: string; companyId: string }): Promise<Response> {
  const base = apiBaseUrl();
  if (!base) throw new ApiError(0, "LEDGERLY_API_URL is not set (demo mode)");
  const visitor = await import("./visitor").then((m) => m.visitorHeaders()).catch(() => ({}));
  const res = await fetch(new URL(`/api/v1/${path.replace(/^\//, "")}`, base), {
    headers: { Accept: "application/json, application/zip", ...visitor, Authorization: `Bearer ${opts.token}`, "X-Company-Id": opts.companyId },
    cache: "no-store",
  });
  if (!res.ok) {
    const body = await res.json().catch(() => null);
    throw new ApiError(res.status, (body as { message?: string } | null)?.message ?? res.statusText, body);
  }
  return res;
}

/**
 * A file sent to the API as multipart (attachments, 063), under the user's own session. The API decides the file's
 * type by its content and checks the permission again.
 */
export async function apiUpload(path: string, form: FormData, opts: { token: string; companyId: string }): Promise<unknown> {
  const base = apiBaseUrl();
  if (!base) throw new ApiError(0, "LEDGERLY_API_URL is not set (demo mode)");
  const visitor = await import("./visitor").then((m) => m.visitorHeaders()).catch(() => ({}));
  const res = await fetch(new URL(`/api/v1/${path.replace(/^\//, "")}`, base), {
    method: "POST", body: form, cache: "no-store",
    headers: { Accept: "application/json", ...visitor, Authorization: `Bearer ${opts.token}`, "X-Company-Id": opts.companyId },
  });
  const body = await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, (body as { message?: string } | null)?.message ?? res.statusText, body);
  return body;
}

/** A file kept with a document (063). */
export interface Attachment {
  id: string;
  file_name: string;
  content_type: "application/pdf" | "image/png" | "image/jpeg" | "image/webp";
  size_bytes: number;
  created_at: string;
  added_by: string;
}

export type AttachmentKind = "invoice" | "bill" | "expense";
export const attachmentPath = (kind: AttachmentKind) => ({ invoice: "invoices", bill: "bills", expense: "expenses" })[kind];

/** GET public/quote (065): a quote as its customer sees it through the emailed link. */
export interface PublicQuote {
  company: { name: string; tin: string | null; email: string | null; phone: string | null };
  customer: string | null;
  quote: {
    number: string; issue_date: string; valid_until: string | null; currency: string; subtotal: string; tax_total: string; total: string;
    notes: string | null; status: string; lines: { description: string; quantity: string; unit_price: string; amount: string }[];
  };
  answer: "accepted" | "declined" | null;
  answered_at: string | null;
  expires_at: string;
  can_answer: boolean;
}

export interface User {
  id: string;
  name: string;
  email: string;
  /** True once an authenticator app is set up: sign-in then asks for a code. */
  mfa_enabled: boolean;
  /** True once the emailed link was opened; needed to create a company. */
  email_verified: boolean;
}

export interface AuthResult {
  user: User;
  token: string;
}

/** GET /auth/demo: the demo company and the roles one can sign in as (404 when demo sign-in is off). */
export interface DemoOptions {
  company: string;
  roles: { role: string; label: string; about: string }[];
}

/** The password was right, but an authenticator code is needed before a token is issued. */
export interface MfaChallenge {
  mfa_required: true;
  challenge: string;
}

export const ledgerly = {
  login: (body: { email: string; password: string }) => api<AuthResult | MfaChallenge>("auth/login", { method: "POST", body }),
  loginWithCode: (body: { challenge: string; code?: string; recovery_code?: string }) =>
    api<AuthResult>("auth/login/mfa", { method: "POST", body }),
  me: (token: string) => api<{ user: User }>("auth/me", { token }),
  demoOptions: () => api<DemoOptions>("auth/demo"),
  demoLogin: (role: string) => api<AuthResult & { role: string }>("auth/demo", { method: "POST", body: { role } }),
  mfaEnrol: (token: string) => api<{ secret: string; otpauth_uri: string }>("auth/mfa/enrol", { method: "POST", token }),
  mfaConfirm: (code: string, token: string) => api<{ recovery_codes: string[] }>("auth/mfa/confirm", { method: "POST", body: { code }, token }),
  register: (body: { name: string; email: string; password: string }) => api<AuthResult>("auth/register", { method: "POST", body }),
  logout: (token: string) => api<null>("auth/logout", { method: "POST", token }),
  logoutEverywhere: (token: string) => api<null>("auth/logout-everywhere", { method: "POST", token }),
  sessions: (token: string) => api<{ data: SignedInSession[] }>("auth/sessions", { token }),
  endDeviceSession: (id: string, token: string) => api<null>(`auth/sessions/${encodeURIComponent(id)}`, { method: "DELETE", token }),
  verifyEmail: (token: string) => api<{ message: string }>("auth/email/verify", { method: "POST", body: { token } }),
  resendVerification: (token: string) => api<{ message: string }>("auth/email/resend", { method: "POST", token }),
  forgotPassword: (email: string) => api<{ message: string }>("auth/password/forgot", { method: "POST", body: { email } }),
  invitation: (token: string) => api<Invitation>("auth/invitation", { query: { token } }),
  publicQuote: (token: string) => api<PublicQuote>("public/quote", { query: { token } }),
  answerPublicQuote: (body: { token: string; answer: "accepted" | "declined"; name: string; note?: string }) =>
    api<{ answer: string; status: string }>("public/quote/answer", { method: "POST", body }),
  acceptInvitation: (body: { token: string; name?: string; password?: string }) =>
    api<{ accepted: boolean; company: string; email: string }>("auth/invitation/accept", { method: "POST", body }),
  resetPassword: (body: { token: string; password: string }) => api<{ message: string }>("auth/password/reset", { method: "POST", body }),
  changePassword: (body: { current_password: string; password: string }, token: string) =>
    api<{ token: string }>("auth/password", { method: "POST", body, token }),
  companies: (token: string) => api<{ data: Company[] }>("companies", { token }),
  createCompany: (body: Record<string, unknown>, token: string) => api<Company>("companies", { method: "POST", body, token }),
  companySettings: (ctx: RequestOptions) => api<CompanySettings>("company", ctx),
  saveCompanySettings: (body: Record<string, unknown>, ctx: RequestOptions) => api<CompanySettings>("company", { ...ctx, method: "PUT", body }),
  setPeriodLock: (body: { books_closed_until: string | null; reason?: string }, ctx: RequestOptions) =>
    api<{ books_closed_until: string | null }>("company/period-lock", { ...ctx, method: "PUT", body }),
  currencies: (ctx: RequestOptions) => api<Currencies>("currencies", ctx),
  setCurrency: (body: { currency: string; enabled: boolean }, ctx: RequestOptions) =>
    api<{ currency: string; is_enabled: boolean }>("currencies", { ...ctx, method: "PUT", body }),
  addExchangeRate: (body: { currency: string; rate_date: string; rate: string }, ctx: RequestOptions) =>
    api<{ id: string }>("exchange-rates", { ...ctx, method: "POST", body }),
  accounts: (ctx: RequestOptions) => api<{ data: Account[] }>("accounts", ctx),
  journal: (q: { from?: string; to?: string; source?: string; search?: string; page?: string }, ctx: RequestOptions) =>
    api<{ data: JournalRow[]; page: number; more: boolean; can: { create: boolean } }>("journal-entries", { ...ctx, query: q }),
  journalEntry: (id: string, ctx: RequestOptions) => api<JournalDetail>(`journal-entries/${encodeURIComponent(id)}`, ctx),
  postJournal: (body: Record<string, unknown>, ctx: RequestOptions) => api<JournalDetail | { request: JournalRequest }>("journal-entries", { ...ctx, method: "POST", body }),
  reclassify: (body: { line_ids: string[]; to_account_id: string; date: string; memo?: string }, ctx: RequestOptions) =>
    api<JournalDetail | { request: JournalRequest }>("journal-entries/reclassify", { ...ctx, method: "POST", body }),
  journalRequests: (status: string, ctx: RequestOptions) =>
    api<{ status: string; data: JournalRequest[]; pending: number }>("journal-requests", { ...ctx, query: { status } }),
  journalRequest: (id: string, ctx: RequestOptions) => api<JournalRequest>(`journal-requests/${encodeURIComponent(id)}`, ctx),
  decideJournalRequest: (id: string, step: "approve" | "reject" | "withdraw", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<JournalRequest | JournalDetail>(`journal-requests/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  recurringJournals: (ctx: RequestOptions) => api<{ data: RecurringJournal[]; due: number; can: { create: boolean } }>("recurring-journals", ctx),
  recurringJournal: (id: string, ctx: RequestOptions) => api<RecurringJournal>(`recurring-journals/${encodeURIComponent(id)}`, ctx),
  saveRecurringJournal: (id: string | null, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<RecurringJournal>(id ? `recurring-journals/${encodeURIComponent(id)}` : "recurring-journals", { ...ctx, method: id ? "PUT" : "POST", body }),
  recurringJournalStep: (id: string, step: "pause" | "resume", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<RecurringJournal>(`recurring-journals/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  postDueRecurring: (ctx: RequestOptions) =>
    api<{ data: { id: string; name: string; posted: number[]; error: string | null }[]; posted: number }>("recurring-journals/post-due", { ...ctx, method: "POST", body: {} }),
  fixedAssets: (ctx: RequestOptions) => api<FixedAssetRegister>("fixed-assets", ctx),
  taxDepreciation: (yearEnd: string | undefined, ctx: RequestOptions) =>
    api<TaxDepreciationYear>("fixed-assets/tax-depreciation", { ...ctx, query: yearEnd ? { year_end: yearEnd } : {} }),
  fixedAsset: (id: string, ctx: RequestOptions) => api<FixedAssetDetail>(`fixed-assets/${encodeURIComponent(id)}`, ctx),
  saveFixedAsset: (id: string | null, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<FixedAssetDetail>(id ? `fixed-assets/${encodeURIComponent(id)}` : "fixed-assets", { ...ctx, method: id ? "PUT" : "POST", body }),
  disposeFixedAsset: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<FixedAssetDetail>(`fixed-assets/${encodeURIComponent(id)}/dispose`, { ...ctx, method: "POST", body }),
  depreciationPreview: (periodEnd: string | undefined, ctx: RequestOptions) =>
    api<{ period_end: string; lines: { asset_id: string; code: string; name: string; amount: string }[]; total: string }>("depreciation/preview", { ...ctx, query: { period_end: periodEnd } }),
  runDepreciation: (periodEnd: string, ctx: RequestOptions) =>
    api<{ entry: { id: string; number: number }; total: string; assets: number }>("depreciation-runs", { ...ctx, method: "POST", body: { period_end: periodEnd } }),
  projects: (ctx: RequestOptions) => api<{ data: Project[]; can: { manage: boolean } }>("projects", ctx),
  project: (id: string, q: { from?: string; to?: string }, ctx: RequestOptions) => api<ProjectDetail>(`projects/${encodeURIComponent(id)}`, { ...ctx, query: q }),
  saveProject: (id: string | null, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<ProjectDetail>(id ? `projects/${encodeURIComponent(id)}` : "projects", { ...ctx, method: id ? "PUT" : "POST", body }),
  timeEntries: (q: { project_id?: string; from?: string; to?: string }, ctx: RequestOptions) =>
    api<{ data: TimeEntry[]; can: { manage: boolean } }>("time-entries", { ...ctx, query: q }),
  logTime: (body: Record<string, unknown>, ctx: RequestOptions) => api<TimeEntry>("time-entries", { ...ctx, method: "POST", body }),
  removeTime: (id: string, ctx: RequestOptions) => api<null>(`time-entries/${encodeURIComponent(id)}`, { ...ctx, method: "DELETE" }),
  addMilestone: (projectId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ data: ProjectMilestone[] }>(`projects/${encodeURIComponent(projectId)}/milestones`, { ...ctx, method: "POST", body }),
  removeMilestone: (projectId: string, id: string, ctx: RequestOptions) =>
    api<{ data: ProjectMilestone[] }>(`projects/${encodeURIComponent(projectId)}/milestones/${encodeURIComponent(id)}`, { ...ctx, method: "DELETE" }),
  billMilestone: (projectId: string, id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ invoice: { id: string; total: string } }>(`projects/${encodeURIComponent(projectId)}/milestones/${encodeURIComponent(id)}/bill`, { ...ctx, method: "POST", body }),
  billTime: (projectId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ invoice: { id: string; total: string }; entries: number }>(`projects/${encodeURIComponent(projectId)}/bill-time`, { ...ctx, method: "POST", body }),
  taxWorksheet: (q: { kind?: string; from?: string; to?: string }, ctx: RequestOptions) => api<TaxWorksheet>("tax/worksheet", { ...ctx, query: q }),
  taxCalendar: (ctx: RequestOptions) => api<TaxCalendar>("tax/calendar", ctx),
  taxAuditFile: (q: { part: string; from?: string; to?: string }, ctx: RequestOptions) =>
    api<{ filename: string; csv: string; rows: number; truncated: boolean }>("tax/audit-file", { ...ctx, query: q }),
  taxReturns: (ctx: RequestOptions) => api<{ data: TaxReturn[]; can: { file: boolean } }>("tax-returns", ctx),
  taxReturn: (id: string, ctx: RequestOptions) => api<TaxReturn>(`tax-returns/${encodeURIComponent(id)}`, ctx),
  fileTaxReturn: (body: Record<string, unknown>, ctx: RequestOptions) => api<TaxReturn>("tax-returns", { ...ctx, method: "POST", body }),
  settleTaxReturn: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<TaxReturn>(`tax-returns/${encodeURIComponent(id)}/settle`, { ...ctx, method: "POST", body }),
  reviewQueue: (q: { from?: string; to?: string; reviewed?: string }, ctx: RequestOptions) => api<ReviewQueue>("journal-entries/review", { ...ctx, query: q }),
  reviewEntry: (id: string, note: string | null, ctx: RequestOptions) =>
    api<{ id: string; number: number; flags: string[] }>(`journal-entries/${encodeURIComponent(id)}/review`, { ...ctx, method: "POST", body: note ? { note } : {} }),
  reverseJournal: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<JournalDetail>(`journal-entries/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
  chart: (archived: boolean, ctx: RequestOptions) =>
    api<{ data: ChartAccount[]; kinds: Record<string, string[]>; can: { manage: boolean } }>("chart", { ...ctx, query: { include_archived: archived ? "1" : undefined } }),
  addAccount: (body: Record<string, unknown>, ctx: RequestOptions) => api<ChartAccount>("accounts", { ...ctx, method: "POST", body }),
  changeAccount: (id: string, body: Record<string, unknown>, ctx: RequestOptions) => api<ChartAccount>(`accounts/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  openingBalances: (ctx: RequestOptions) => api<OpeningBalances>("opening-balances", ctx),
  enterOpeningDocument: (type: "invoice" | "bill", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ id: string; number: string }>(`opening-balances/${type === "bill" ? "bills" : "invoices"}`, { ...ctx, method: "POST", body }),
  saveOpeningBalances: (lines: { account_id: string; debit?: string; credit?: string }[], ctx: RequestOptions) =>
    api<OpeningBalances>("opening-balances", { ...ctx, method: "PUT", body: { lines } }),
  dimensions: (ctx: RequestOptions) => api<{ data: Dimension[] }>("dimensions", ctx),
  addDimension: (body: { kind: string; code: string; name: string }, ctx: RequestOptions) =>
    api<Dimension>("dimensions", { ...ctx, method: "POST", body }),
  updateDimension: (id: string, body: { name?: string; is_active?: boolean }, ctx: RequestOptions) =>
    api<Dimension>(`dimensions/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  numbering: (ctx: RequestOptions) => api<{ data: Numbering[] }>("numbering", ctx),
  saveNumbering: (body: Omit<Numbering, "next_number">, ctx: RequestOptions) => api<Numbering>("numbering", { ...ctx, method: "PUT", body }),
  customers: (q: { search?: string; include_archived?: string }, ctx: RequestOptions) =>
    api<{ data: Customer[]; can: { create: boolean; edit: boolean } }>("customers", { ...ctx, query: q }),
  customer: (id: string, ctx: RequestOptions) => api<Customer>(`customers/${encodeURIComponent(id)}`, ctx),
  createCustomer: (body: Record<string, unknown>, ctx: RequestOptions) => api<Customer>("customers", { ...ctx, method: "POST", body }),
  updateCustomer: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<Customer>(`customers/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  openInvoices: (contactId: string, ctx: RequestOptions) =>
    api<{ data: Invoice[] }>("invoices", { ...ctx, query: { contact_id: contactId, open: "1" } }),
  openBills: (contactId: string, ctx: RequestOptions) =>
    api<{ data: Bill[] }>("bills", { ...ctx, query: { contact_id: contactId, open: "1" } }),
  paymentsMade: (ctx: RequestOptions) => api<{ data: PaymentReceived[] }>("payments-made", ctx),
  paymentMade: (id: string, ctx: RequestOptions) =>
    api<PaymentReceived & { withholding_certificate: string | null; unallocated: string; contact?: { id: string; name: string; tax_id?: string | null; payout?: Payout }; can: { reverse: boolean; allocate: boolean; refund: boolean } }>(
      `payments-made/${encodeURIComponent(id)}`, ctx),
  payBills: (body: Record<string, unknown>, ctx: RequestOptions) =>
    api<PaymentReceived & { warnings: { type: string; message: string }[] }>("payments-made", { ...ctx, method: "POST", body }),
  reversePaymentMade: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-made/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
  withholdingAdvice: (supplierId: string, ctx: RequestOptions) => api<WithholdingAdvice>(`payments-made/withholding/${encodeURIComponent(supplierId)}`, ctx),
  apAgeing: (asOf: string | undefined, ctx: RequestOptions) => api<ArAgeing>("reports/ap-ageing", { ...ctx, query: { as_of: asOf } }),
  supplierStatement: (id: string, q: { from?: string; to?: string }, ctx: RequestOptions) =>
    api<Omit<CustomerStatement, "customer"> & { supplier: CustomerStatement["customer"] }>(`suppliers/${encodeURIComponent(id)}/statement`, { ...ctx, query: q }),
  allocatePayment: (id: string, body: { allocations: { invoice_id: string; amount: string }[] }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-received/${encodeURIComponent(id)}/allocate`, { ...ctx, method: "POST", body }),
  allocatePaymentMade: (id: string, body: { allocations: { bill_id: string; amount: string }[] }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-made/${encodeURIComponent(id)}/allocate`, { ...ctx, method: "POST", body }),
  refunds: (ctx: RequestOptions) => api<{ data: (PaymentReceived & { direction: "in" | "out" })[] }>("refunds", ctx),
  refund: (id: string, ctx: RequestOptions) =>
    api<PaymentReceived & { direction: "in" | "out"; refunds_payment_id: string | null; can: { reverse: boolean } }>(`refunds/${encodeURIComponent(id)}`, ctx),
  recordRefund: (side: "customer" | "supplier", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<PaymentReceived>(`refunds/${side}`, { ...ctx, method: "POST", body }),
  reverseRefund: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<PaymentReceived>(`refunds/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
  paymentsReceived: (ctx: RequestOptions) => api<{ data: PaymentReceived[] }>("payments-received", ctx),
  paymentReceived: (id: string, ctx: RequestOptions) =>
    api<PaymentReceived & { unallocated: string; can: { allocate: boolean; reverse: boolean; refund: boolean } }>(`payments-received/${encodeURIComponent(id)}`, ctx),
  receivePayment: (body: Record<string, unknown>, ctx: RequestOptions) => api<PaymentReceived>("payments-received", { ...ctx, method: "POST", body }),
  cashCounts: (accountId: string, ctx: RequestOptions) => api<{ data: CashCount[]; can: { count: boolean } }>(`bank-accounts/${encodeURIComponent(accountId)}/cash-counts`, ctx),
  countCash: (accountId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ counted: string; book_balance: string; difference: string; entry: number | null }>(`bank-accounts/${encodeURIComponent(accountId)}/cash-counts`, { ...ctx, method: "POST", body }),
  cheques: (accountId: string, direction: "out" | "in", ctx: RequestOptions) =>
    api<ChequeRegister>(`bank-accounts/${encodeURIComponent(accountId)}/cheques`, { ...ctx, query: { direction } }),
  bounceCheque: (id: string, body: { date: string; fee?: string; reason?: string }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-received/${encodeURIComponent(id)}/bounce`, { ...ctx, method: "POST", body }),
  reversePayment: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-received/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
  arAgeing: (asOf: string | undefined, ctx: RequestOptions) => api<ArAgeing>("reports/ar-ageing", { ...ctx, query: { as_of: asOf } }),
  customerStatement: (id: string, q: { from?: string; to?: string }, ctx: RequestOptions) =>
    api<CustomerStatement>(`customers/${encodeURIComponent(id)}/statement`, { ...ctx, query: q }),
  suppliers: (q: { search?: string; include_archived?: string }, ctx: RequestOptions) =>
    api<{ data: Supplier[]; can: { create: boolean; edit: boolean; bill: boolean } }>("suppliers", { ...ctx, query: q }),
  supplier: (id: string, ctx: RequestOptions) => api<Supplier>(`suppliers/${encodeURIComponent(id)}`, ctx),
  createSupplier: (body: Record<string, unknown>, ctx: RequestOptions) => api<Supplier>("suppliers", { ...ctx, method: "POST", body }),
  updateSupplier: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<Supplier>(`suppliers/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  bills: (ctx: RequestOptions) => api<{ data: Bill[] }>("bills", ctx),
  supplierCredits: (ctx: RequestOptions) =>
    api<{ data: (Bill & { original?: { id: string; number: string | null } | null })[] }>("supplier-credits", ctx),
  createSupplierCredit: (billId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<BillDetail>(`bills/${encodeURIComponent(billId)}/credits`, { ...ctx, method: "POST", body }),
  applySupplierCredit: (creditId: string, body: { bill_id: string; amount: string }, ctx: RequestOptions) =>
    api<unknown>(`supplier-credits/${encodeURIComponent(creditId)}/apply`, { ...ctx, method: "POST", body }),
  bill: (id: string, ctx: RequestOptions) => api<BillDetail>(`bills/${encodeURIComponent(id)}`, ctx),
  createBill: (body: Record<string, unknown>, ctx: RequestOptions) => api<BillDetail>("bills", { ...ctx, method: "POST", body }),
  approveBill: (id: string, ctx: RequestOptions) => api<BillDetail>(`bills/${encodeURIComponent(id)}/approve`, { ...ctx, method: "POST" }),
  submitBill: (id: string, ctx: RequestOptions) => api<BillDetail>(`bills/${encodeURIComponent(id)}/submit`, { ...ctx, method: "POST" }),
  voidBill: (id: string, reason: string, ctx: RequestOptions) =>
    api<BillDetail>(`bills/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body: { reason } }),
  deliverSalesOrder: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<DeliveryNote>(`sales-orders/${encodeURIComponent(id)}/deliver`, { ...ctx, method: "POST", body }),
  deliveryNotes: (ctx: RequestOptions) => api<{ data: DeliveryNoteSummary[] }>("delivery-notes", ctx),
  deliveryNote: (id: string, ctx: RequestOptions) => api<DeliveryNote>(`delivery-notes/${encodeURIComponent(id)}`, ctx),
  voidDeliveryNote: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<DeliveryNote>(`delivery-notes/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body }),
  uninvoicedGoods: (ctx: RequestOptions) => api<UninvoicedGoods>("delivery-notes/uninvoiced", ctx),
  salesReceipts: (ctx: RequestOptions) => api<{ data: SalesReceipt[] }>("sales-receipts", ctx),
  salesReceipt: (id: string, ctx: RequestOptions) => api<SalesReceipt>(`sales-receipts/${encodeURIComponent(id)}`, ctx),
  createSalesReceipt: (body: Record<string, unknown>, ctx: RequestOptions) => api<SalesReceipt>("sales-receipts", { ...ctx, method: "POST", body }),
  voidSalesReceipt: (id: string, reason: string, ctx: RequestOptions) =>
    api<SalesReceipt>(`sales-receipts/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body: { reason } }),
  expenses: (ctx: RequestOptions) => api<{ data: Expense[] }>("expenses", ctx),
  expense: (id: string, ctx: RequestOptions) => api<Expense>(`expenses/${encodeURIComponent(id)}`, ctx),
  createExpense: (body: Record<string, unknown>, ctx: RequestOptions) => api<Expense>("expenses", { ...ctx, method: "POST", body }),
  voidExpense: (id: string, reason: string, ctx: RequestOptions) =>
    api<Expense>(`expenses/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body: { reason } }),
  items: (q: { search?: string; include_archived?: string; type?: string }, ctx: RequestOptions) =>
    api<{ data: Item[]; can: { edit: boolean; adjust: boolean } }>("items", { ...ctx, query: q }),
  item: (id: string, ctx: RequestOptions) => api<Item>(`items/${encodeURIComponent(id)}`, ctx),
  createItem: (body: Record<string, unknown>, ctx: RequestOptions) => api<Item>("items", { ...ctx, method: "POST", body }),
  updateItem: (id: string, body: Record<string, unknown>, ctx: RequestOptions) => api<Item>(`items/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  setItemComponents: (id: string, components: { item_id: string; quantity: string }[], ctx: RequestOptions) =>
    api<Item>(`items/${encodeURIComponent(id)}/components`, { ...ctx, method: "PUT", body: { components } }),
  addItemUnit: (id: string, body: { name: string; factor: string }, ctx: RequestOptions) =>
    api<Item>(`items/${encodeURIComponent(id)}/units`, { ...ctx, method: "POST", body }),
  archiveItemUnit: (id: string, unitId: string, ctx: RequestOptions) =>
    api<Item>(`items/${encodeURIComponent(id)}/units/${encodeURIComponent(unitId)}/archive`, { ...ctx, method: "POST", body: {} }),
  warehouses: (ctx: RequestOptions) => api<{ data: Warehouse[] }>("warehouses", ctx),
  people: (ctx: RequestOptions) => api<{ data: { id: string; name: string }[] }>("people", ctx),
  members: (ctx: RequestOptions) => api<{ data: Member[]; roles: string[]; grantable: Grantable[] }>("members", ctx),
  inviteMember: (body: { email: string; name?: string; role: string; permissions?: string[] }, ctx: RequestOptions) =>
    api<Member>("members/invitations", { ...ctx, method: "POST", body }),
  changeMember: (id: string, action: "role" | "resend" | "deactivate" | "reactivate", role: string | undefined, ctx: RequestOptions, permissions?: string[]) =>
    action === "role"
      ? api<Member>(`members/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body: { role, ...(role === "custom" ? { permissions } : {}) } })
      : api<Member>(`members/${encodeURIComponent(id)}/${action}`, { ...ctx, method: "POST", body: {} }),
  createWarehouse: (name: string, ctx: RequestOptions) => api<Warehouse>("warehouses", { ...ctx, method: "POST", body: { name } }),
  stock: (ctx: RequestOptions) => api<StockOnHand>("stock", ctx),
  importRows: (kind: string, csv: string, check: boolean, ctx: RequestOptions) =>
    api<ImportResult>(`imports/${encodeURIComponent(kind)}`, { ...ctx, method: "POST", body: { csv, check } }),
  countStock: (body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ counted: number; differences: { item: string; books: string; counted: string; difference: string; value: string }[]; net: string }>("stock/counts", { ...ctx, method: "POST", body }),
  issueStock: (body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ move_id: string; amount: string; unit_cost: string; journal_entry_id: string | null }>("stock/issues", { ...ctx, method: "POST", body }),
  adjustStock: (body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ move_id: string; amount: string; unit_cost: string }>("stock/adjustments", { ...ctx, method: "POST", body }),
  binCard: (q: { item_id: string; warehouse_id: string; from: string; to: string }, ctx: RequestOptions) => api<BinCard>("reports/bin-card", { ...ctx, query: q }),
  transferStock: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ value: string }>("stock/transfers", { ...ctx, method: "POST", body }),
  stockBatches: (q: { within_days?: number }, ctx: RequestOptions) =>
    api<{ data: { item_id: string; item_name: string; sku: string | null; unit: string; batch_id: string; number: string; expires_on: string | null;
      warehouse_id: string; warehouse: string; quantity: string; expired: boolean }[]; today: string }>("stock/batches", { ...ctx, query: q.within_days === undefined ? {} : { within_days: String(q.within_days) } }),
  reorder: (ctx: RequestOptions) =>
    api<{ data: { item: { id: string; sku: string | null; name: string; unit: string; reorder_point: string; max_level: string | null; purchase_price: string | null };
      supplier: { id: string; name: string | null } | null; on_hand: string; on_order: string; reserved: string; position: string; suggested: string }[] }>("stock/reorder", ctx),
  orderLowStock: (body: { warehouse_id: string; lines: { item_id: string; quantity: string }[] }, ctx: RequestOptions) =>
    api<{ data: { id: string; contact_id: string; lines: number; total: string }[] }>("stock/reorder/purchase-orders", { ...ctx, method: "POST", body }),
  stockMovement: (q: { from?: string; to?: string; warehouse_id?: string }, ctx: RequestOptions) => api<StockMovement>("reports/stock-movement", { ...ctx, query: q }),
  stockAgeing: (q: { as_of?: string; warehouse_id?: string }, ctx: RequestOptions) => api<StockAgeing>("reports/stock-ageing", { ...ctx, query: q }),
  inventoryValuation: (asOf: string | undefined, ctx: RequestOptions) =>
    api<{ as_of: string; rows: { item_id: string; name: string; sku: string | null; unit: string; warehouse: string; quantity: string; value: string }[]; stock_value: string; inventory_account: string; difference: string }>(
      "reports/inventory-valuation", { ...ctx, query: { as_of: asOf } }),
  bankAccounts: (ctx: RequestOptions) => api<{ data: BankAccount[]; can: { manage: boolean; transfer: boolean; deposit: boolean } }>("bank-accounts", ctx),
  bankAccount: (id: string, ctx: RequestOptions) => api<BankAccount>(`bank-accounts/${encodeURIComponent(id)}`, ctx),
  createBankAccount: (body: Record<string, unknown>, ctx: RequestOptions) => api<BankAccount>("bank-accounts", { ...ctx, method: "POST", body }),
  updateBankAccount: (id: string, body: Record<string, unknown>, ctx: RequestOptions) => api<BankAccount>(`bank-accounts/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  bankRegister: (id: string, q: { from?: string; to?: string }, ctx: RequestOptions) => api<BankRegister>(`bank-accounts/${encodeURIComponent(id)}/register`, { ...ctx, query: q }),
  transferMoney: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ number: number }>("banking/transfers", { ...ctx, method: "POST", body }),
  statementLines: (id: string, status: string, ctx: RequestOptions) =>
    api<StatementLines>(`bank-accounts/${encodeURIComponent(id)}/statement-lines`, { ...ctx, query: { status } }),
  reconciliations: (accountId: string, ctx: RequestOptions) => api<Reconciliations>(`bank-accounts/${encodeURIComponent(accountId)}/reconciliations`, ctx),
  startReconciliation: (accountId: string, body: { statement_date: string; ending_balance: string }, ctx: RequestOptions) =>
    api<Reconciliation>(`bank-accounts/${encodeURIComponent(accountId)}/reconciliations`, { ...ctx, method: "POST", body }),
  reconciliation: (id: string, ctx: RequestOptions) => api<Reconciliation>(`reconciliations/${encodeURIComponent(id)}`, ctx),
  saveReconciliation: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<Reconciliation>(`reconciliations/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  completeReconciliation: (id: string, ctx: RequestOptions) => api<Reconciliation>(`reconciliations/${encodeURIComponent(id)}/complete`, { ...ctx, method: "POST" }),
  undoReconciliation: (id: string, reason: string, ctx: RequestOptions) =>
    api<Reconciliation>(`reconciliations/${encodeURIComponent(id)}/undo`, { ...ctx, method: "POST", body: { reason } }),
  discardReconciliation: (id: string, reason: string, ctx: RequestOptions) =>
    api<{ discarded: boolean }>(`reconciliations/${encodeURIComponent(id)}`, { ...ctx, method: "DELETE", body: { reason } }),
  importStatement: (id: string, body: { csv: string; file_name?: string }, ctx: RequestOptions) =>
    api<{ imported: number; skipped: number; from: string; to: string }>(`bank-accounts/${encodeURIComponent(id)}/statements`, { ...ctx, method: "POST", body }),
  repeatInvoice: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ id: string }>(`invoices/${encodeURIComponent(id)}/repeat`, { ...ctx, method: "POST", body }),
  recurringInvoices: (ctx: RequestOptions) => api<{ data: RecurringInvoice[]; can: { manage: boolean } }>("recurring-invoices", ctx),
  recurringInvoiceStep: (id: string, step: "pause" | "resume" | "make-due", ctx: RequestOptions) =>
    api<{ made?: string[]; error?: string | null; next_on: string }>(`recurring-invoices/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body: {} }),
  bankRules: (ctx: RequestOptions) => api<{ data: BankRule[]; can: { manage: boolean } }>("bank-rules", ctx),
  createBankRule: (body: Record<string, unknown>, ctx: RequestOptions) => api<BankRule>("bank-rules", { ...ctx, method: "POST", body }),
  updateBankRule: (id: string, body: Record<string, unknown>, ctx: RequestOptions) => api<BankRule>(`bank-rules/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  deleteBankRule: (id: string, ctx: RequestOptions) => api<{ deleted: boolean }>(`bank-rules/${encodeURIComponent(id)}`, { ...ctx, method: "DELETE" }),
  applyBankRules: (accountId: string, ctx: RequestOptions) =>
    api<{ categorised: number; skipped: { line_id: string; rule: string; reason: string }[] }>(`bank-accounts/${encodeURIComponent(accountId)}/apply-rules`, { ...ctx, method: "POST", body: {} }),
  reviewStatementLine: (id: string, step: "match" | "categorise" | "split" | "exclude" | "undo", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<StatementLine>(`statement-lines/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  depositReceipts: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ number: number }>("banking/deposits", { ...ctx, method: "POST", body }),
  invoices: (ctx: RequestOptions) => api<{ data: Invoice[] }>("invoices", ctx),
  invoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}`, ctx),
  createInvoice: (body: Record<string, unknown>, ctx: RequestOptions) => api<InvoiceDetail>("invoices", { ...ctx, method: "POST", body }),
  attachments: (kind: AttachmentKind, id: string, ctx: RequestOptions) =>
    api<{ data: Attachment[]; can: { add: boolean } }>(`${attachmentPath(kind)}/${encodeURIComponent(id)}/attachments`, ctx),
  removeAttachment: (id: string, ctx: RequestOptions) => api<unknown>(`attachments/${encodeURIComponent(id)}/remove`, { ...ctx, method: "POST", body: {} }),
  postDatedCheques: (ctx: RequestOptions) => api<PostDatedCheques>("post-dated-cheques", ctx),
  recordPostDatedCheque: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ id: string }>("post-dated-cheques", { ...ctx, method: "POST", body }),
  presentPostDatedCheque: (id: string, ctx: RequestOptions) =>
    api<{ payment_id: string }>(`post-dated-cheques/${encodeURIComponent(id)}/present`, { ...ctx, method: "POST", body: {} }),
  cancelPostDatedCheque: (id: string, reason: string, ctx: RequestOptions) =>
    api<unknown>(`post-dated-cheques/${encodeURIComponent(id)}/cancel`, { ...ctx, method: "POST", body: { reason } }),
  landedCosts: (billId: string, ctx: RequestOptions) => api<LandedCosts>(`bills/${encodeURIComponent(billId)}/landed-costs`, ctx),
  applyLandedCost: (billId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ amount: string; inventory: string; cost_of_sales: string }>(`bills/${encodeURIComponent(billId)}/landed-costs`, { ...ctx, method: "POST", body }),
  quotes: (kind: QuoteKind, ctx: RequestOptions) => api<{ data: QuoteSummary[] }>(quotePath(kind), ctx),
  quote: (kind: QuoteKind, id: string, ctx: RequestOptions) => api<QuoteDetail>(`${quotePath(kind)}/${encodeURIComponent(id)}`, ctx),
  createQuote: (kind: QuoteKind, body: Record<string, unknown>, ctx: RequestOptions) => api<QuoteDetail>(quotePath(kind), { ...ctx, method: "POST", body }),
  updateQuote: (kind: QuoteKind, id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<QuoteDetail>(`${quotePath(kind)}/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  quoteStep: (kind: QuoteKind, id: string, step: "send" | "accept" | "decline" | "extend" | "cancel" | "close", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<QuoteDetail>(`${quotePath(kind)}/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  emailQuote: (kind: QuoteKind, id: string, note: string | undefined, ctx: RequestOptions) =>
    api<{ sent_to: string; status: QuoteStatus; number: string | null }>(`${quotePath(kind)}/${encodeURIComponent(id)}/email`, { ...ctx, method: "POST", body: note ? { note } : {} }),
  convertQuote: (kind: QuoteKind, id: string, to: "invoice" | "sales_order", number: string | undefined, ctx: RequestOptions,
    lines?: { line_id: string; quantity: string }[]) =>
    api<{ id: string; type: "invoice" | "sales_order"; number: string | null; status: string }>(`${quotePath(kind)}/${encodeURIComponent(id)}/convert`,
      { ...ctx, method: "POST", body: { to, ...(number ? { number } : {}), ...(lines ? { lines } : {}) } }),
  backOrders: (ctx: RequestOptions) => api<BackOrders>("sales-orders/back-orders", ctx),
  purchaseOrders: (ctx: RequestOptions) => api<{ data: PurchaseOrderSummary[] }>("purchase-orders", ctx),
  purchaseOrder: (id: string, ctx: RequestOptions) => api<PurchaseOrderDetail>(`purchase-orders/${encodeURIComponent(id)}`, ctx),
  createPurchaseOrder: (body: Record<string, unknown>, ctx: RequestOptions) => api<PurchaseOrderDetail>("purchase-orders", { ...ctx, method: "POST", body }),
  updatePurchaseOrder: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<PurchaseOrderDetail>(`purchase-orders/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  purchaseOrderStep: (id: string, step: "issue" | "cancel" | "close", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<PurchaseOrderDetail>(`purchase-orders/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  billPurchaseOrder: (id: string, lines: { line_id: string; quantity: string }[] | undefined, ctx: RequestOptions) =>
    api<{ id: string; type: "bill"; number: string | null; status: string }>(`purchase-orders/${encodeURIComponent(id)}/bill`,
      { ...ctx, method: "POST", body: lines ? { lines } : {} }),
  openPurchaseOrders: (ctx: RequestOptions) => api<BackOrders>("purchase-orders/open", ctx),
  receivePurchaseOrder: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<GoodsReceipt>(`purchase-orders/${encodeURIComponent(id)}/receive`, { ...ctx, method: "POST", body }),
  goodsReceipts: (ctx: RequestOptions) => api<{ data: GoodsReceiptSummary[] }>("goods-receipts", ctx),
  goodsReceipt: (id: string, ctx: RequestOptions) => api<GoodsReceipt>(`goods-receipts/${encodeURIComponent(id)}`, ctx),
  voidGoodsReceipt: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<GoodsReceipt>(`goods-receipts/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body }),
  unbilledGoods: (ctx: RequestOptions) => api<UnbilledGoods>("goods-receipts/unbilled", ctx),
  updateInvoice: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  approveInvoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/approve`, { ...ctx, method: "POST" }),
  submitInvoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/submit`, { ...ctx, method: "POST" }),
  voidInvoice: (id: string, reason: string, ctx: RequestOptions) =>
    api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body: { reason } }),
  createCreditNote: (invoiceId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ id: string }>(`invoices/${encodeURIComponent(invoiceId)}/credit-notes`, { ...ctx, method: "POST", body }),
  creditNotes: (ctx: RequestOptions) =>
    api<{ data: (Invoice & { original?: { id: string; number: string | null } | null })[] }>("credit-notes", ctx),
  holdRetention: (id: string, body: { amount?: string; percent?: string; held_on: string }, ctx: RequestOptions) =>
    api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/retention`, { ...ctx, method: "POST", body }),
  batchInvoice: (id: string, body: { contact_ids: string[]; issue_date: string; post: boolean }, ctx: RequestOptions) =>
    api<{ data: { id: string; number: string | null; status: string; contact: string; total: string }[] }>(`invoices/${encodeURIComponent(id)}/batch`, { ...ctx, method: "POST", body }),
  lateInterest: (id: string, ctx: RequestOptions) => api<LateInterestPreview>(`invoices/${encodeURIComponent(id)}/interest`, ctx),
  chargeInterest: (id: string, body: { through: string }, ctx: RequestOptions) =>
    api<{ id: string; number: string | null }>(`invoices/${encodeURIComponent(id)}/interest`, { ...ctx, method: "POST", body }),
  writeOffs: (id: string, ctx: RequestOptions) => api<InvoiceWriteOffs>(`invoices/${encodeURIComponent(id)}/write-offs`, ctx),
  writeOff: (id: string, body: { amount?: string; date: string; reason: string }, ctx: RequestOptions) =>
    api<InvoiceWriteOff>(`invoices/${encodeURIComponent(id)}/write-offs`, { ...ctx, method: "POST", body }),
  reverseWriteOff: (id: string, body: { date: string; reason?: string }, ctx: RequestOptions) =>
    api<InvoiceWriteOff>(`invoices/${encodeURIComponent(id)}/write-offs/reverse`, { ...ctx, method: "POST", body }),
  releaseRetention: (id: string, releasedOn: string, ctx: RequestOptions) =>
    api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/retention/release`, { ...ctx, method: "POST", body: { released_on: releasedOn } }),
  emailInvoice: (id: string, note: string | undefined, ctx: RequestOptions) =>
    api<{ sent_to: string }>(`invoices/${encodeURIComponent(id)}/email`, { ...ctx, method: "POST", body: note ? { note } : {} }),
  remindInvoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/remind`, { ...ctx, method: "POST", body: {} }),
  applyCredit: (creditNoteId: string, body: { invoice_id: string; amount: string }, ctx: RequestOptions) =>
    api<unknown>(`credit-notes/${encodeURIComponent(creditNoteId)}/apply`, { ...ctx, method: "POST", body }),
  taxRates: (ctx: RequestOptions) => api<{ data: TaxRate[] }>("tax-rates", ctx),
  revaluations: (ctx: RequestOptions) => api<{ data: RevaluationRun[]; can: { revalue: boolean } }>("revaluations", ctx),
  revaluationPreview: (asOf: string, rates: Record<string, string>, ctx: RequestOptions) =>
    api<RevaluationPreview>("revaluations/preview", { ...ctx, query: { as_of: asOf, ...Object.fromEntries(Object.entries(rates).map(([k, v]) => [`rates[${k}]`, v])) } }),
  postRevaluation: (body: { as_of: string; rates: Record<string, string> }, ctx: RequestOptions) => api<RevaluationRun>("revaluations", { ...ctx, method: "POST", body }),
  fiscalYears: (ctx: RequestOptions) => api<{ data: FiscalYear[] }>("fiscal-years", ctx),
  yearClosing: (id: string, ctx: RequestOptions) =>
    api<{ lines: { account_id: string; code: string; name: string; type: string; balance: string }[]; net_profit: string }>(`fiscal-years/${encodeURIComponent(id)}/closing`, ctx),
  closeYear: (id: string, ctx: RequestOptions) => api<{ net_profit: string; entry: { id: string; number: number } }>(`fiscal-years/${encodeURIComponent(id)}/close`, { ...ctx, method: "POST" }),
  adjustedTrialBalance: (id: string, ctx: RequestOptions) => api<AdjustedTrialBalance>(`fiscal-years/${encodeURIComponent(id)}/adjusted-trial-balance`, ctx),
  postAdjustment: (id: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ adjustment: { id: string; number: number }; closing: { number: number }; net_profit: string }>(`fiscal-years/${encodeURIComponent(id)}/adjustments`, { ...ctx, method: "POST", body }),
  finaliseAudit: (id: string, ctx: RequestOptions) => api<AdjustedTrialBalance>(`fiscal-years/${encodeURIComponent(id)}/finalise`, { ...ctx, method: "POST" }),
  reopenYear: (id: string, reason: string, ctx: RequestOptions) => api<{ reversal: { number: number } }>(`fiscal-years/${encodeURIComponent(id)}/reopen`, { ...ctx, method: "POST", body: { reason } }),
  closeChecklist: (periodEnd: string | undefined, ctx: RequestOptions) => api<CloseChecklist>("close-checklist", { ...ctx, query: { period_end: periodEnd } }),
  generalLedger: (q: Record<string, string | undefined>, ctx: RequestOptions) => api<GeneralLedger>("reports/general-ledger", { ...ctx, query: q }),
  trialBalance: (asOf: string, ctx: RequestOptions) => api<{ data: TrialBalanceRow[] }>("reports/trial-balance", { ...ctx, query: { as_of: asOf } }),
  balanceSheet: (asOf: string, ctx: RequestOptions) => api<BalanceSheet>("reports/balance-sheet", { ...ctx, query: { as_of: asOf } }),
  cashFlow: (q: { from?: string; to?: string }, ctx: RequestOptions) => api<CashFlowStatement>("reports/cash-flow", { ...ctx, query: q }),
  kpis: (ctx: RequestOptions) => api<Kpis>("reports/kpis", ctx),
  cashForecast: (weeks: number, ctx: RequestOptions) => api<CashForecast>("reports/cash-forecast", { ...ctx, query: { weeks: String(weeks) } }),
  changesInEquity: (q: { from?: string; to?: string }, ctx: RequestOptions) => api<ChangesInEquity>("reports/changes-in-equity", { ...ctx, query: q }),
  financialHealth: (q: { from?: string; to?: string }, ctx: RequestOptions) => api<FinancialHealth>("reports/financial-health", { ...ctx, query: q }),
  auditLog: (q: Record<string, string | undefined>, ctx: RequestOptions) => api<AuditLog>("audit-log", { ...ctx, query: q }),
  verifyAuditLog: (ctx: RequestOptions) => api<{ intact: boolean; broken_at: number | null; rows: number; checked_at: string }>("audit-log/verify", ctx),
  savedReports: (ctx: RequestOptions) => api<{ data: SavedReport[] }>("saved-reports", ctx),
  savedReport: (id: string, ctx: RequestOptions) => api<SavedReport>(`saved-reports/${encodeURIComponent(id)}`, ctx),
  saveReport: (id: string | null, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<SavedReport>(id ? `saved-reports/${encodeURIComponent(id)}` : "saved-reports", { ...ctx, method: id ? "PUT" : "POST", body }),
  deleteSavedReport: (id: string, ctx: RequestOptions) => api<{ removed: boolean }>(`saved-reports/${encodeURIComponent(id)}`, { ...ctx, method: "DELETE" }),
  recordExport: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ recorded: boolean; at: string }>("reports/exports", { ...ctx, method: "POST", body }),
  profitByDimension: (q: { kind: string; from?: string; to?: string }, ctx: RequestOptions) => api<ProfitByDimension>("reports/profit-by-dimension", { ...ctx, query: q }),
  customReport: (q: Record<string, string | undefined>, ctx: RequestOptions) => api<CustomReport>("reports/custom", { ...ctx, query: q }),
  subscription: (ctx: RequestOptions) => api<Subscription>("subscription", ctx),
  documentAnalysis: (side: "sales" | "purchases", q: { group: string; from?: string; to?: string }, ctx: RequestOptions) =>
    api<DocumentAnalysis>(`reports/${side}`, { ...ctx, query: q }),
  profitAndLoss: (q: { from: string; to: string; compare_from?: string; compare_to?: string; basis?: "accrual" | "cash" }, ctx: RequestOptions) =>
    api<ProfitAndLoss>("reports/profit-and-loss", { ...ctx, query: q }),
};

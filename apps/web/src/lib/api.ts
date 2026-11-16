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
  can: { manage: boolean; close_periods: boolean; reopen_periods: boolean; enter_rates: boolean; manage_dimensions: boolean };
  options: { tax_id_label: string; calendars: string[]; legal_forms: string[] };
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
  can: { enter: boolean };
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
  lines: { account_id: string; code: string | null; account: string | null; debit: string | null; credit: string | null; description: string | null }[] | null;
  can: { approve: boolean; withdraw: boolean };
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
  is_active: boolean;
  on_hand?: string;
  value?: string;
  balances?: { warehouse: { id: string; name: string }; quantity: string; value: string }[];
  can?: { edit: boolean; adjust: boolean };
}

export interface Warehouse { id: string; name: string; is_active: boolean }

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
  line_no: number;
  description: string;
  quantity: string;
  unit_price: string;
  discount_rate: string;
  tax_rate_id: string | null;
  account_id: string;
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

export interface InvoiceDetail extends Invoice {
  type: "invoice" | "credit_note";
  /** A credit note names the invoice it corrects. */
  original_document_id: string | null;
  original?: { id: string; number: string | null } | null;
  fx_rate: string;
  notes: string | null;
  contact: Contact & { email?: string | null };
  lines: InvoiceLine[];
  warnings: { type: string; message: string }[];
  can: { edit: boolean; approve: boolean; void: boolean; credit: boolean; apply: boolean; refund?: boolean };
}

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
    lines: { date: string; entry_id: string; entry: number; source_type: string; memo: string | null; description: string | null; contact: string | null; debit: string; credit: string; balance: string }[] }[];
}

export interface TrialBalanceRow {
  account_id: string;
  code: string;
  name: string;
  type: string;
  debit: string;
  credit: string;
}

export interface ProfitAndLossRow {
  code: string;
  name: string;
  current: string;
  previous: string | null;
}

export interface ProfitAndLoss {
  sections: { income: ProfitAndLossRow[]; cost_of_sales: ProfitAndLossRow[]; expenses: ProfitAndLossRow[] };
  totals: Record<"income" | "cost_of_sales" | "gross_profit" | "expenses" | "net_profit", { current: string; previous: string | null }>;
}

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
  verifyEmail: (token: string) => api<{ message: string }>("auth/email/verify", { method: "POST", body: { token } }),
  resendVerification: (token: string) => api<{ message: string }>("auth/email/resend", { method: "POST", token }),
  forgotPassword: (email: string) => api<{ message: string }>("auth/password/forgot", { method: "POST", body: { email } }),
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
  journalRequests: (status: string, ctx: RequestOptions) =>
    api<{ status: string; data: JournalRequest[]; pending: number }>("journal-requests", { ...ctx, query: { status } }),
  journalRequest: (id: string, ctx: RequestOptions) => api<JournalRequest>(`journal-requests/${encodeURIComponent(id)}`, ctx),
  decideJournalRequest: (id: string, step: "approve" | "reject" | "withdraw", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<JournalRequest | JournalDetail>(`journal-requests/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  reverseJournal: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<JournalDetail>(`journal-entries/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
  chart: (archived: boolean, ctx: RequestOptions) =>
    api<{ data: ChartAccount[]; kinds: Record<string, string[]>; can: { manage: boolean } }>("chart", { ...ctx, query: { include_archived: archived ? "1" : undefined } }),
  addAccount: (body: Record<string, unknown>, ctx: RequestOptions) => api<ChartAccount>("accounts", { ...ctx, method: "POST", body }),
  changeAccount: (id: string, body: Record<string, unknown>, ctx: RequestOptions) => api<ChartAccount>(`accounts/${encodeURIComponent(id)}`, { ...ctx, method: "PUT", body }),
  openingBalances: (ctx: RequestOptions) => api<OpeningBalances>("opening-balances", ctx),
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
  warehouses: (ctx: RequestOptions) => api<{ data: Warehouse[] }>("warehouses", ctx),
  createWarehouse: (name: string, ctx: RequestOptions) => api<Warehouse>("warehouses", { ...ctx, method: "POST", body: { name } }),
  stock: (ctx: RequestOptions) => api<StockOnHand>("stock", ctx),
  adjustStock: (body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ move_id: string; amount: string; unit_cost: string }>("stock/adjustments", { ...ctx, method: "POST", body }),
  binCard: (q: { item_id: string; warehouse_id: string; from: string; to: string }, ctx: RequestOptions) => api<BinCard>("reports/bin-card", { ...ctx, query: q }),
  transferStock: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ value: string }>("stock/transfers", { ...ctx, method: "POST", body }),
  reorder: (ctx: RequestOptions) =>
    api<{ data: { item: { id: string; sku: string | null; name: string; unit: string; reorder_point: string; purchase_price: string | null }; on_hand: string }[] }>("stock/reorder", ctx),
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
  reviewStatementLine: (id: string, step: "match" | "categorise" | "exclude" | "undo", body: Record<string, unknown>, ctx: RequestOptions) =>
    api<StatementLine>(`statement-lines/${encodeURIComponent(id)}/${step}`, { ...ctx, method: "POST", body }),
  depositReceipts: (body: Record<string, unknown>, ctx: RequestOptions) => api<{ number: number }>("banking/deposits", { ...ctx, method: "POST", body }),
  invoices: (ctx: RequestOptions) => api<{ data: Invoice[] }>("invoices", ctx),
  invoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}`, ctx),
  createInvoice: (body: Record<string, unknown>, ctx: RequestOptions) => api<InvoiceDetail>("invoices", { ...ctx, method: "POST", body }),
  approveInvoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/approve`, { ...ctx, method: "POST" }),
  submitInvoice: (id: string, ctx: RequestOptions) => api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/submit`, { ...ctx, method: "POST" }),
  voidInvoice: (id: string, reason: string, ctx: RequestOptions) =>
    api<InvoiceDetail>(`invoices/${encodeURIComponent(id)}/void`, { ...ctx, method: "POST", body: { reason } }),
  createCreditNote: (invoiceId: string, body: Record<string, unknown>, ctx: RequestOptions) =>
    api<{ id: string }>(`invoices/${encodeURIComponent(invoiceId)}/credit-notes`, { ...ctx, method: "POST", body }),
  creditNotes: (ctx: RequestOptions) =>
    api<{ data: (Invoice & { original?: { id: string; number: string | null } | null })[] }>("credit-notes", ctx),
  applyCredit: (creditNoteId: string, body: { invoice_id: string; amount: string }, ctx: RequestOptions) =>
    api<unknown>(`credit-notes/${encodeURIComponent(creditNoteId)}/apply`, { ...ctx, method: "POST", body }),
  taxRates: (ctx: RequestOptions) => api<{ data: TaxRate[] }>("tax-rates", ctx),
  generalLedger: (q: Record<string, string | undefined>, ctx: RequestOptions) => api<GeneralLedger>("reports/general-ledger", { ...ctx, query: q }),
  trialBalance: (asOf: string, ctx: RequestOptions) => api<{ data: TrialBalanceRow[] }>("reports/trial-balance", { ...ctx, query: { as_of: asOf } }),
  profitAndLoss: (q: { from: string; to: string; compare_from?: string; compare_to?: string }, ctx: RequestOptions) =>
    api<ProfitAndLoss>("reports/profit-and-loss", { ...ctx, query: q }),
};

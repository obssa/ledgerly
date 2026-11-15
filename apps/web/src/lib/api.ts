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
  can: { edit: boolean; approve: boolean; void: boolean; credit: boolean; apply: boolean };
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
  allocations_count?: number;
  allocations?: { id: string; amount: string; document_id: string; document?: { id: string; number: string | null; issue_date?: string; total: string; amount_due: string; status: string } }[];
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
  paymentsReceived: (ctx: RequestOptions) => api<{ data: PaymentReceived[] }>("payments-received", ctx),
  paymentReceived: (id: string, ctx: RequestOptions) => api<PaymentReceived>(`payments-received/${encodeURIComponent(id)}`, ctx),
  receivePayment: (body: Record<string, unknown>, ctx: RequestOptions) => api<PaymentReceived>("payments-received", { ...ctx, method: "POST", body }),
  reversePayment: (id: string, body: { date: string; reason: string }, ctx: RequestOptions) =>
    api<PaymentReceived>(`payments-received/${encodeURIComponent(id)}/reverse`, { ...ctx, method: "POST", body }),
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
  profitAndLoss: (q: { from: string; to: string; compare_from?: string; compare_to?: string }, ctx: RequestOptions) =>
    api<ProfitAndLoss>("reports/profit-and-loss", { ...ctx, query: q }),
};

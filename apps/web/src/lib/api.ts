/**
 * Typed client for the Ledgerly API (apps/api/routes/api.php). Amounts are decimal strings, never numbers.
 * Without LEDGERLY_API_URL the app runs in demo mode on sample data (src/lib/demo.ts).
 */

export interface Company {
  id: string;
  legal_name: string;
  trading_name: string | null;
  country_code: string;
  base_currency: string;
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
  number: string;
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

export const apiBaseUrl = process.env.LEDGERLY_API_URL ?? process.env.NEXT_PUBLIC_LEDGERLY_API_URL;
export const isDemoMode = !apiBaseUrl;

export async function api<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  if (!apiBaseUrl) throw new ApiError(0, "LEDGERLY_API_URL is not set (demo mode)");

  const url = new URL(`/api/v1/${path.replace(/^\//, "")}`, apiBaseUrl);
  for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined) url.searchParams.set(k, v);

  const res = await fetch(url, {
    method: opts.method ?? "GET",
    headers: {
      Accept: "application/json",
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

export const ledgerly = {
  invoices: (ctx: RequestOptions) => api<{ data: Invoice[] }>("invoices", ctx),
  invoice: (id: string, ctx: RequestOptions) => api<Invoice>(`invoices/${id}`, ctx),
  approveInvoice: (id: string, ctx: RequestOptions) => api<Invoice>(`invoices/${id}/approve`, { ...ctx, method: "POST" }),
  profitAndLoss: (q: { from: string; to: string; compare_from?: string; compare_to?: string }, ctx: RequestOptions) =>
    api<ProfitAndLoss>("reports/profit-and-loss", { ...ctx, query: q }),
};

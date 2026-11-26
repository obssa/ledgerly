import { parse, toString } from "./decimal";
import { chooseColumns, type ColumnDef } from "./columns";
export { moveColumn } from "./columns";

/**
 * The transaction reports' columns, as QuickBooks offers them under Customize, Columns: every row has every field
 * (TransactionReport in the API); the reader picks which to show and in what order, how to sort and group. The choice
 * lives in the URL (cols, sort, dir, group), so a report is shared, saved and exported as shown.
 */
export type TxRow = {
  id: string; date: string; type: string; number: string | null; name: string | null; tin: string | null; memo: string | null;
  due_date: string | null; days_past_due: number | null; status: string | null; amount: string | null; open_balance: string | null;
  tax: string | null; amount_before_tax: string | null; foreign_amount: string | null; foreign_open_balance: string | null;
  currency: string | null; exchange_rate: string | null; location: string | null; account: string | null; method: string | null;
  applied_to: string | null; created_by: string | null; created_at: string | null; posted_by: string | null;
};
export type TxColumn = keyof TxRow;

export const COLUMNS: ColumnDef<TxColumn>[] = [
  { key: "date", label: "Date", group: "Transaction", kind: "date" },
  { key: "type", label: "Transaction type", group: "Transaction", kind: "text" },
  { key: "number", label: "No.", group: "Transaction", kind: "text" },
  { key: "status", label: "Status", group: "Transaction", kind: "text" },
  { key: "memo", label: "Memo", group: "Transaction", kind: "text" },
  { key: "name", label: "Name", group: "Customer or supplier", kind: "text" },
  { key: "tin", label: "TIN", group: "Customer or supplier", kind: "text" },
  { key: "due_date", label: "Due date", group: "Dates", kind: "date" },
  { key: "days_past_due", label: "Past due (days)", group: "Dates", kind: "count" },
  { key: "amount", label: "Amount", group: "Amounts", kind: "money" },
  { key: "open_balance", label: "Open balance", group: "Amounts", kind: "money" },
  { key: "amount_before_tax", label: "Amount before tax", group: "Amounts", kind: "money" },
  { key: "tax", label: "Tax", group: "Amounts", kind: "money" },
  { key: "foreign_amount", label: "Foreign amount", group: "Foreign currency", kind: "money" },
  { key: "foreign_open_balance", label: "Foreign open balance", group: "Foreign currency", kind: "money" },
  { key: "currency", label: "Currency", group: "Foreign currency", kind: "text" },
  { key: "exchange_rate", label: "Exchange rate", group: "Foreign currency", kind: "rate" },
  { key: "location", label: "Location", group: "Tracking", kind: "text" },
  { key: "account", label: "Account", group: "Tracking", kind: "text" },
  { key: "method", label: "Method", group: "Tracking", kind: "text" },
  { key: "applied_to", label: "Applied to", group: "Tracking", kind: "text" },
  { key: "created_by", label: "Created by", group: "People and time", kind: "text" },
  { key: "created_at", label: "Create date", group: "People and time", kind: "date" },
  { key: "posted_by", label: "Posted by", group: "People and time", kind: "text" },
];
const KEYS = new Set<string>(COLUMNS.map((c) => c.key));
export const FOREIGN: readonly TxColumn[] = ["foreign_amount", "foreign_open_balance", "currency", "exchange_rate"];
export const column = (key: TxColumn) => COLUMNS.find((c) => c.key === key)!;
export const isMoney = (key: TxColumn) => column(key).kind === "money";

/** The reports, each with QuickBooks' default columns, sort and grouping, and what it lists. */
export const TX_REPORTS = {
  "transaction-list": { api: "transactions", title: "Transaction list by date", fallback: "this_month",
    columns: ["date", "type", "number", "name", "memo", "account", "amount", "posted_by"], group: "none", statuses: false },
  "invoice-list": { api: "invoices", title: "Invoice list", fallback: "this_fiscal_year_to_date",
    columns: ["date", "number", "name", "due_date", "amount", "open_balance", "status", "created_by"], group: "none", statuses: true },
  "open-invoices": { api: "open_invoices", title: "Open invoices", fallback: "all",
    columns: ["date", "type", "number", "name", "due_date", "days_past_due", "amount", "open_balance", "foreign_amount", "foreign_open_balance", "currency"], group: "name", statuses: false },
  "bill-list": { api: "bills", title: "Bill list", fallback: "this_fiscal_year_to_date",
    columns: ["date", "number", "name", "due_date", "amount", "open_balance", "status", "created_by"], group: "none", statuses: true },
  "unpaid-bills": { api: "unpaid_bills", title: "Unpaid bills", fallback: "all",
    columns: ["date", "type", "number", "name", "due_date", "days_past_due", "amount", "open_balance", "foreign_amount", "foreign_open_balance", "currency"], group: "name", statuses: false },
  "payments-received": { api: "payments_received", title: "Payments received", fallback: "this_month",
    columns: ["date", "number", "name", "method", "account", "amount", "applied_to", "created_by"], group: "none", statuses: false },
  "payments-made": { api: "payments_made", title: "Payments made", fallback: "this_month",
    columns: ["date", "number", "name", "method", "account", "amount", "applied_to", "created_by"], group: "none", statuses: false },
} as const satisfies Record<string, { api: string; title: string; fallback: string; columns: readonly TxColumn[]; group: string; statuses: boolean }>;
export type TxReport = keyof typeof TX_REPORTS;
export const isTxReport = (v: unknown): v is TxReport => typeof v === "string" && v in TX_REPORTS;

export const GROUPS = [["none", "No grouping"], ["name", "Customer or supplier"], ["type", "Transaction type"], ["month", "Month"], ["status", "Status"]] as const;
export type TxGroup = (typeof GROUPS)[number][0];

/** What a type is called. */
const TYPE_LABELS: Record<string, string> = {
  invoice: "Invoice", credit_note: "Credit note", bill: "Bill", vendor_credit: "Supplier credit", sales_receipt: "Cash sale",
  payment_received: "Payment received", payment_made: "Payment made", manual: "Journal entry", reversal: "Reversal", opening: "Opening balance",
  closing: "Year-end close", adjustment: "Adjustment", transfer: "Transfer", expense: "Expense", payment_allocation: "Payment applied",
};
export const typeLabel = (t: string) => TYPE_LABELS[t] ?? t.charAt(0).toUpperCase() + t.slice(1).replace(/_/g, " ");
const STATUS_LABELS: Record<string, string> = { awaiting_approval: "Awaiting approval", part_paid: "Part paid" };
export const statusLabel = (s: string | null) => (s ? STATUS_LABELS[s] ?? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, " ") : "");

type Query = Record<string, string | string[] | undefined>;

/** The columns shown, from the URL (lib/columns.ts); no choice: the report's defaults. */
export function pickColumns(q: Query, defaults: readonly TxColumn[]): TxColumn[] {
  return chooseColumns(q, COLUMNS, defaults);
}

export function pickSort(q: Query, cols: TxColumn[]): { sort: TxColumn; dir: "asc" | "desc" } {
  const sort = typeof q.sort === "string" && KEYS.has(q.sort) ? (q.sort as TxColumn) : cols.includes("date") ? "date" : cols[0];
  return { sort, dir: q.dir === "desc" ? "desc" : "asc" };
}

export function pickGroup(q: Query, fallback: TxGroup): TxGroup {
  return GROUPS.some(([g]) => g === q.group) ? (q.group as TxGroup) : fallback;
}

/** Sorted as asked: amounts as amounts, dates and words as text; blanks last. */
export function sortRows(rows: TxRow[], sort: TxColumn, dir: "asc" | "desc"): TxRow[] {
  const kind = column(sort).kind;
  const value = (r: TxRow) => r[sort];
  return [...rows].sort((a, b) => {
    const x = value(a), y = value(b);
    if (x === null || x === "") return 1;
    if (y === null || y === "") return -1;
    const c = kind === "money" ? (parse(String(x)) < parse(String(y)) ? -1 : parse(String(x)) > parse(String(y)) ? 1 : 0)
      : kind === "count" || kind === "rate" ? Number(x) - Number(y) : String(x).localeCompare(String(y));
    return dir === "desc" ? -c : c;
  });
}

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/** Rows in groups, each with its subtotals of the amount columns; one group of everything when not grouped. */
export function groupRows(rows: TxRow[], group: TxGroup): { key: string; label: string; rows: TxRow[] }[] {
  if (group === "none") return [{ key: "", label: "", rows }];
  const keyOf = (r: TxRow) => group === "name" ? r.name ?? "" : group === "type" ? r.type : group === "status" ? r.status ?? "" : r.date.slice(0, 7);
  const labelOf = (k: string) => group === "type" ? typeLabel(k) : group === "status" ? statusLabel(k) : group === "month" ? `${MONTHS[Number(k.slice(5, 7)) - 1]} ${k.slice(0, 4)}` : k || "No name";
  const out = new Map<string, TxRow[]>();
  for (const r of rows) out.set(keyOf(r), [...(out.get(keyOf(r)) ?? []), r]);
  return [...out.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([k, rs]) => ({ key: k, label: labelOf(k), rows: rs }));
}

/** Totals of the amount columns shown (foreign ones only when all the rows are in one currency). */
export function totals(rows: TxRow[], cols: TxColumn[]): Partial<Record<TxColumn, string>> {
  const out: Partial<Record<TxColumn, string>> = {};
  const currencies = new Set(rows.map((r) => r.currency).filter(Boolean));
  for (const c of cols) {
    if (!isMoney(c)) continue;
    if ((c === "foreign_amount" || c === "foreign_open_balance") && currencies.size !== 1) continue;
    out[c] = toString(rows.reduce((sum, r) => (r[c] ? sum + parse(String(r[c])) : sum), 0n));
  }
  return out;
}

/** The same choices as URL fields (for links, exports and saving). */
export function customQuery(cols: TxColumn[], sort: { sort: TxColumn; dir: "asc" | "desc" }, group: TxGroup, extra: Record<string, string | undefined> = {}): Record<string, string> {
  return Object.fromEntries(Object.entries({ cols: cols.join(","), sort: sort.sort, dir: sort.dir, group, ...extra }).filter(([, v]) => v)) as Record<string, string>;
}

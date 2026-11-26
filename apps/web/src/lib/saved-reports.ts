import { RANGE_PRESETS } from "./periods";
/* Module 10: saved reports: which page shows each report, and the link that opens a saved one for its period today. */

export const SAVEABLE: Record<string, { label: string; path: string; atADate?: boolean }> = {
  "profit-and-loss": { label: "Profit and loss", path: "/reports" },
  "balance-sheet": { label: "Balance sheet", path: "/reports/balance-sheet", atADate: true },
  "trial-balance": { label: "Trial balance", path: "/reports/trial-balance", atADate: true },
  "general-ledger": { label: "General ledger", path: "/reports/general-ledger" },
  "cash-flow": { label: "Cash flow statement", path: "/reports/cash-flow" },
  "changes-in-equity": { label: "Changes in equity", path: "/reports/changes-in-equity" },
  "financial-health": { label: "Financial health", path: "/reports/financial-health" },
  "profit-by-dimension": { label: "Profit by location, class or project", path: "/reports/profit-by-dimension" },
  custom: { label: "Custom report", path: "/reports/custom" },
  sales: { label: "Sales analysis", path: "/reports/sales" },
  purchases: { label: "Purchases analysis", path: "/reports/purchases" },
  "ar-ageing": { label: "AR ageing", path: "/reports/ar-ageing", atADate: true },
  "ap-ageing": { label: "AP ageing", path: "/reports/ap-ageing", atADate: true },
  "inventory-valuation": { label: "Inventory valuation", path: "/reports/inventory-valuation", atADate: true },
  "stock-movement": { label: "Stock movement", path: "/reports/stock-movement" },
  "stock-ageing": { label: "Stock ageing", path: "/reports/stock-ageing", atADate: true },
  "transaction-list": { label: "Transaction list by date", path: "/reports/transaction-list" },
  "invoice-list": { label: "Invoice list", path: "/reports/invoice-list" },
  "open-invoices": { label: "Open invoices", path: "/reports/open-invoices" },
  "bill-list": { label: "Bill list", path: "/reports/bill-list" },
  "unpaid-bills": { label: "Unpaid bills", path: "/reports/unpaid-bills" },
  "payments-received": { label: "Payments received", path: "/reports/payments-received" },
  "payments-made": { label: "Payments made", path: "/reports/payments-made" },
};

/** Every period the report pages offer (lib/periods.ts), worked out again each time the report is opened or sent. */
export const PERIODS: [string, string][] = RANGE_PRESETS.map((p) => [p.key, p.key === "custom" ? "Custom dates" : p.label]);

export const SCHEDULES: [string, string][] = [["none", "Not sent"], ["weekly", "Every Monday"], ["monthly", "On the 1st of each month"]];

/** The report options a saved report keeps (each report takes some of them; the API checks which). */
export const OPTION_KEYS = ["group", "kind", "account_id", "warehouse_id", "rows", "columns", "measure", "account_type", "project_id", "location_id", "class_id",
  "cols", "sort", "dir", "status", "view", "interval", "periods"] as const;

/** The report page for a saved report, for the dates its period covers today, with its options. */
export function savedReportUrl(r: { report: string; period?: string; covers: { from?: string | null; to?: string | null; as_of?: string | null }; options: Record<string, string> }): string {
  const page = SAVEABLE[r.report];
  if (!page) return "/reports";
  const q = new URLSearchParams();
  // A rolling period opens as itself ("Last fiscal year"), so the page names it and moves with the calendar.
  const rolling = r.period && r.period !== "custom" ? (r.period === "year_to_date" ? "this_fiscal_year_to_date" : r.period) : null;
  if (page.atADate) q.set("as_of", r.covers.as_of ?? r.covers.to ?? "");
  else if (rolling) q.set("range", rolling);
  else {
    q.set("from", r.covers.from ?? "");
    q.set("to", r.covers.to ?? "");
  }
  for (const k of OPTION_KEYS) if (typeof r.options[k] === "string" && r.options[k]) q.set(k, r.options[k]);
  return `${page.path}?${q.toString()}`;
}

/** The body for saving a report from its form. */
export function savedReportBody(form: FormData): Record<string, unknown> {
  const value = (n: string) => (typeof form.get(n) === "string" ? (form.get(n) as string).trim() : "");
  const period = value("period");
  const options: Record<string, string> = {};
  for (const k of OPTION_KEYS) if (value(k)) options[k] = value(k);
  return {
    name: value("name"), report: value("report"), period, schedule: value("schedule") || "none", options,
    ...(period === "custom" ? { from: value("from") || null, to: value("to") || null } : {}),
  };
}

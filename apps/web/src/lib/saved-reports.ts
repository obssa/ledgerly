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
  sales: { label: "Sales analysis", path: "/reports/sales" },
  purchases: { label: "Purchases analysis", path: "/reports/purchases" },
  "ar-ageing": { label: "AR ageing", path: "/reports/ar-ageing", atADate: true },
  "ap-ageing": { label: "AP ageing", path: "/reports/ap-ageing", atADate: true },
  "inventory-valuation": { label: "Inventory valuation", path: "/reports/inventory-valuation", atADate: true },
  "stock-movement": { label: "Stock movement", path: "/reports/stock-movement" },
  "stock-ageing": { label: "Stock ageing", path: "/reports/stock-ageing", atADate: true },
};

export const PERIODS: [string, string][] = [
  ["this_month", "This month"], ["last_month", "Last month"], ["this_quarter", "This quarter"], ["last_quarter", "Last quarter"],
  ["year_to_date", "Fiscal year to date"], ["custom", "Custom dates"],
];

export const SCHEDULES: [string, string][] = [["none", "Not sent"], ["weekly", "Every Monday"], ["monthly", "On the 1st of each month"]];

const OPTION_KEYS = ["group", "kind", "account_id", "warehouse_id"] as const;

/** The report page for a saved report, for the dates its period covers today, with its options. */
export function savedReportUrl(r: { report: string; covers: { from?: string; to?: string; as_of?: string }; options: Record<string, string> }): string {
  const page = SAVEABLE[r.report];
  if (!page) return "/reports";
  const q = new URLSearchParams();
  if (page.atADate) q.set("as_of", r.covers.as_of ?? r.covers.to ?? "");
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

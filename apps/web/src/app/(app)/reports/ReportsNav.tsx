import Link from "@/components/Link";
import { SAVEABLE } from "@/lib/saved-reports";
import { PrintButton } from "./PrintButton";

/** The reports, grouped by the accounting cycle; every entry is a page that exists. */
const CYCLE: { stage: string; reports: [string, string][] }[] = [
  { stage: "Financial statements", reports: [["Profit and loss", "/reports"], ["Balance sheet", "/reports/balance-sheet"], ["Cash flow statement", "/reports/cash-flow"],
    ["Changes in equity", "/reports/changes-in-equity"], ["Statements and notes (to print)", "/reports/notes-pack"], ["Cash forecast (13 weeks)", "/reports/cash-forecast"]] },
  { stage: "Ledger and trial balance", reports: [["Trial balance", "/reports/trial-balance"], ["General ledger", "/reports/general-ledger"], ["Journal report", "/reports/journal"], ["Journal entries", "/journal"],
    ["Audit adjustments", "/close"]] },
  { stage: "Transactions", reports: [["Transaction list by date", "/reports/transaction-list"], ["Invoice list", "/reports/invoice-list"], ["Open invoices", "/reports/open-invoices"],
    ["Payments received", "/reports/payments-received"], ["Bill list", "/reports/bill-list"], ["Unpaid bills", "/reports/unpaid-bills"], ["Payments made", "/reports/payments-made"]] },
  { stage: "Customers and suppliers", reports: [["Sales analysis", "/reports/sales"], ["AR ageing summary", "/reports/ar-ageing"], ["AR ageing detail", "/reports/ar-ageing?view=detail"], ["Customer statements", "/customers"],
    ["Purchases analysis", "/reports/purchases"], ["AP ageing summary", "/reports/ap-ageing"], ["AP ageing detail", "/reports/ap-ageing?view=detail"], ["Supplier statements", "/suppliers"]] },
  { stage: "Inventory and assets", reports: [["Inventory valuation", "/reports/inventory-valuation"], ["Stock movement", "/reports/stock-movement"],
    ["Stock ageing (slow-moving)", "/reports/stock-ageing"], ["Bin cards", "/inventory"], ["Fixed asset register", "/fixed-assets"]] },
  { stage: "Foreign currency", reports: [["Realized gains and losses", "/reports/exchange-gains"], ["Unrealized gains and losses", "/reports/exchange-gains/unrealized"],
    ["Revalue foreign balances", "/revaluation"]] },
  { stage: "Tax", reports: [["VAT return worksheet", "/tax"], ["Withholding register", "/tax?kind=withholding"]] },
  { stage: "Audit", reports: [["Changes: who changed what", "/reports/changes"], ["Audit log", "/audit"]] },
  { stage: "Saved", reports: [["Saved and scheduled reports", "/reports/saved"]] },
  { stage: "Management", reports: [["Financial health", "/reports/financial-health"], ["Profit by location, class or project", "/reports/profit-by-dimension"], ["Profit by tag", "/reports/by-tag"], ["Custom report", "/reports/custom"],
    ["Project profitability", "/projects"], ["Review queue", "/journal/review"]] },
];

export function ReportsNav({ current }: { current: string }) {
  return (
    <nav aria-label="Reports by accounting cycle" className="min-w-0 flex-[1_1_220px] rounded-[14px] border border-line bg-surface p-3 print:hidden">
      {CYCLE.map((g) => (
        <div key={g.stage} className="mb-2">
          <div className="px-3 pb-1 pt-2 text-[13px] font-bold text-muted">{g.stage}</div>
          {g.reports.map(([label, href]) => (
            <Link key={label} href={href} aria-current={href === current ? "page" : undefined}
              className={`block rounded-lg px-3 py-2 text-sm font-semibold ${href === current ? "bg-[#f0faf7] text-brand" : "hover:bg-[#f0faf7]"}`}>{label}</Link>
          ))}
        </div>
      ))}
    </nav>
  );
}

/** A CSV or Excel download, or print and save as PDF, of the report as shown (each recorded in the audit log), and saving it to open again or be sent. */
export function ExportLink({ report, params }: { report: string; params: Record<string, string | undefined> }) {
  const query = new URLSearchParams(Object.entries(params).filter(([, v]) => v) as [string, string][]).toString();
  return (
    <span className="flex flex-wrap gap-2 print:hidden">
      {SAVEABLE[report] && <Link className="btn" href={`/reports/saved?${new URLSearchParams({ report, ...Object.fromEntries(Object.entries(params).filter(([, v]) => v)) }).toString()}`}>Save</Link>}
      <a className="btn" href={`/reports/export/${report}${query ? `?${query}` : ""}`}>Download CSV</a>
      <a className="btn" href={`/reports/export/${report}?${new URLSearchParams([...new URLSearchParams(query), ["format", "xlsx"]]).toString()}`}>Download Excel</a>
      <PrintButton report={report} params={params} />
    </span>
  );
}


import Link from "next/link";

/** The reports, grouped by the accounting cycle; every entry is a page that exists. */
const CYCLE: { stage: string; reports: [string, string][] }[] = [
  { stage: "Financial statements", reports: [["Profit and loss", "/reports"], ["Balance sheet", "/reports/balance-sheet"], ["Cash flow statement", "/reports/cash-flow"],
    ["Changes in equity", "/reports/changes-in-equity"]] },
  { stage: "Ledger and trial balance", reports: [["Trial balance", "/reports/trial-balance"], ["General ledger", "/reports/general-ledger"], ["Journal report", "/journal"],
    ["Audit adjustments", "/close"]] },
  { stage: "Customers and suppliers", reports: [["Sales analysis", "/reports/sales"], ["AR ageing", "/reports/ar-ageing"], ["Customer statements", "/customers"],
    ["Purchases analysis", "/reports/purchases"], ["AP ageing", "/reports/ap-ageing"], ["Supplier statements", "/suppliers"]] },
  { stage: "Inventory and assets", reports: [["Inventory valuation", "/reports/inventory-valuation"], ["Bin cards", "/inventory"], ["Fixed asset register", "/fixed-assets"]] },
  { stage: "Tax", reports: [["VAT return worksheet", "/tax"], ["Withholding register", "/tax?kind=withholding"]] },
  { stage: "Management", reports: [["Financial health", "/reports/financial-health"], ["Profit by location, class or project", "/reports/profit-by-dimension"],
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

/** From and to: a plain GET form, so it works without JavaScript. */
export function PeriodForm({ from, to, single }: { from?: string; to: string; single?: boolean }) {
  return (
    <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
      {!single && <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={from} /></div>}
      <div><label className="field-label" htmlFor={single ? "as_of" : "to"}>{single ? "As at" : "To"}</label><input className="field" id={single ? "as_of" : "to"} name={single ? "as_of" : "to"} type="date" defaultValue={to} /></div>
      <button type="submit" className="btn">Show</button>
    </form>
  );
}

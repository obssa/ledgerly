import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { measureLines } from "@/lib/compareLinesMeasure";
import { COMPARE_FIELDS, compareQuery, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { exportPeriod } from "@/lib/periodParams";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { rangeLabel } from "@/lib/periods";
import { Card, PageTitle } from "@/components/ui";
import { getDocumentAnalysis, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { perUnit } from "@/lib/decimal";
import { ExportLink, ReportsNav } from "./ReportsNav";

/** Sales or purchases in a period by customer or supplier, item or month (credits taken off, base currency). */
export async function DocumentAnalysisView({ side, q }: { side: "sales" | "purchases"; q: Record<string, string | string[] | undefined> }) {
  const party = side === "sales" ? "Customer" : "Supplier";
  const groups: [string, string][] = [["contact", party], ["item", "Item"], ["month", "Month"], ...(side === "sales" ? [["salesperson", "Salesperson"] as [string, string]] : [])];
  const group = groups.some(([g]) => g === q.group) ? (q.group as string) : "contact";
  const period = await getReportPeriod(q);
  const cmp = pickRangeComparisons(group === "month" ? { c1: "none" } : q, period.picked, period.ctx);
  const diff = pickDiff(q, "pct");
  const own = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [own, ...cmp.periods.map((p) => p.label)];
  const comparedWith = cmp.periods.length ? `Compared with ${cmp.periods.map((p) => p.label).join(", ")}` : null;
  const [r, ...others] = await Promise.all([{ from: period.from, to: period.to }, ...cmp.periods].map((x) => getDocumentAnalysis(side, { group, from: x.from, to: x.to })));
  const lines = cmp.periods.length && r && others.every(Boolean)
    ? measureLines([r, ...others] as NonNullable<typeof r>[], (x) => x.rows, (row) => row.key ?? "none", (row) => row.amount, (row) => row.label, { label: "Total", value: (x) => x.totals.amount })
    : null;
  const label = groups.find(([g]) => g === group)?.[1] ?? party;
  const href = (key: string | null) => (group === "contact" && key ? `/${side === "sales" ? "customers" : "suppliers"}/${encodeURIComponent(key)}` : null);

  return (
    <>
      <PageTitle title={side === "sales" ? "Sales analysis" : "Purchases analysis"} actions={<ExportLink report={side} params={{ group, ...exportPeriod(period.picked), ...(group !== "month" ? compareQuery(cmp.slots, diff) : {}) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["group", ...COMPARE_FIELDS]}>
        <div><label className="field-label" htmlFor="group">By</label>
          <select className="field" id="group" name="group" defaultValue={group}>{groups.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
        {group !== "month" && <CompareFilter slots={cmp.slots} diff={diff} />}
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current={`/reports/${side}`} />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label={side === "sales" ? "Sales" : "Purchases"}>
            <div className="px-3 pt-3"><ReportHeader title={`${side === "sales" ? "Sales" : "Purchases"} by ${label.toLowerCase()}`} period={rangeLabel(period.picked, period.ctx)}
              filters={[`Before tax, ${side === "sales" ? "credit notes" : "supplier credits"} taken off`, comparedWith]} /></div>
            {lines ? <CompareTable first={label} lines={lines} headers={headers} diff={diff} /> : (<div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">{label}</th>{group === "item" && <><th scope="col" className="!text-right">Quantity</th><th scope="col" className="!text-right">Average price</th></>}<th scope="col" className="!text-right">Documents</th>
                  <th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Share</th></tr></thead>
                <tbody>
                  {r.rows.length === 0 && <tr><td colSpan={group === "item" ? 7 : 5} className="text-muted">Nothing posted in the period.</td></tr>}
                  {r.rows.map((row) => {
                    const link = href(row.key);
                    return (
                      <tr key={row.key ?? "none"}>
                        <td>{link ? <a href={link} className="hover:underline">{row.label}</a> : <span className={row.key ? "" : "text-muted"}>{row.label}</span>}</td>
                        {group === "item" && <><td className="num text-right">{row.quantity ?? ""}</td>
                          <td className="num text-right text-muted">{row.quantity && perUnit(row.amount, row.quantity) !== null ? formatAmount(perUnit(row.amount, row.quantity)!) : "—"}</td></>}
                        <td className="num text-right">{row.documents}</td>
                        <td className="num text-right font-semibold">{formatAmount(row.amount)}</td><td className="num text-right">{formatAmount(row.tax)}</td>
                        <td className="num text-right">{row.share === null ? "—" : `${row.share}%`}</td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={group === "item" ? 4 : 2}>Total</td>
                  <td className="num text-right">{formatAmount(r.totals.amount)}</td><td className="num text-right">{formatAmount(r.totals.tax)}</td><td /></tr></tfoot>
              </table>
            </div>)}
            <ReportFooter basis={null} currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

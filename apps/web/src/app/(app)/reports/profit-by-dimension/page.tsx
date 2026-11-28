import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { measureLines } from "@/lib/compareLinesMeasure";
import { COMPARE_FIELDS, compareQuery, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { exportPeriod } from "@/lib/periodParams";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { rangeLabel } from "@/lib/periods";
import { Card, PageTitle } from "@/components/ui";
import { getProfitByDimension, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Profit by location, class or project" };

const KINDS = [["location", "Location"], ["class", "Class"], ["project", "Project"]] as const;

/** Module 10: income, cost of sales, expenses and profit for each location, class or project, from the tagged ledger lines. */
export default async function ProfitByDimensionPage({ searchParams }: PageProps<"/reports/profit-by-dimension">) {
  const q = await searchParams;
  const kind = KINDS.some(([k]) => k === q.kind) ? (q.kind as string) : "location";
  const period = await getReportPeriod(q);
  const cmp = pickRangeComparisons(q, period.picked, period.ctx);
  const diff = pickDiff(q, "pct");
  const own = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [own, ...cmp.periods.map((p) => p.label)];
  const comparedWith = cmp.periods.length ? `Compared with ${cmp.periods.map((p) => p.label).join(", ")}` : null;
  const [r, ...others] = await Promise.all([{ from: period.from, to: period.to }, ...cmp.periods].map((x) => getProfitByDimension({ kind, from: x.from, to: x.to })));
  const lines = cmp.periods.length && r && others.every(Boolean)
    ? measureLines([r, ...others] as NonNullable<typeof r>[], (x) => x.rows, (row) => row.id ?? "none", (row) => row.profit,
      (row) => `${row.code ? `${row.code} ` : ""}${row.name}`, { label: "Total profit", value: (x) => x.totals.profit })
    : null;
  const label = KINDS.find(([k]) => k === kind)?.[1] ?? "Location";

  return (
    <>
      <PageTitle title="Profit by location, class or project" actions={<ExportLink report="profit-by-dimension" params={{ kind, ...exportPeriod(period.picked), ...compareQuery(cmp.slots, diff) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["kind", ...COMPARE_FIELDS]}>
        <div><label className="field-label" htmlFor="kind">By</label>
          <select className="field" id="kind" name="kind" defaultValue={kind}>{KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
        <CompareFilter slots={cmp.slots} diff={diff} />
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/profit-by-dimension" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Profit by dimension">
            <div className="px-3 pt-3"><ReportHeader title={`Profit by ${label.toLowerCase()}`} period={rangeLabel(period.picked, period.ctx)} filters={[lines ? "Profit" : null, comparedWith]} /></div>
            {lines ? <CompareTable first={label} lines={lines} headers={headers} diff={diff} /> : (<div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">{label}</th><th scope="col" className="!text-right">Income</th><th scope="col" className="!text-right">Cost of sales</th>
                  <th scope="col" className="!text-right">Expenses</th><th scope="col" className="!text-right">Profit</th><th scope="col" className="!text-right">Margin</th></tr></thead>
                <tbody>
                  {r.rows.length === 0 && <tr><td colSpan={6} className="text-muted">Nothing posted to income or expenses in the period.</td></tr>}
                  {r.rows.map((row) => (
                    <tr key={row.id ?? "none"}>
                      <td className={row.id ? "" : "text-muted"}>{row.code && <span className="num text-muted">{row.code} </span>}{row.name}</td>
                      <td className="num text-right">{formatAmount(row.income)}</td><td className="num text-right">{formatAmount(row.cost_of_sales)}</td>
                      <td className="num text-right">{formatAmount(row.expenses)}</td>
                      <td className={`num text-right font-semibold ${row.profit.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(row.profit)}</td>
                      <td className="num text-right">{row.margin === null ? "—" : `${row.margin}%`}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td>Total</td><td className="num text-right">{formatAmount(r.totals.income)}</td>
                  <td className="num text-right">{formatAmount(r.totals.cost_of_sales)}</td><td className="num text-right">{formatAmount(r.totals.expenses)}</td>
                  <td className="num text-right">{formatAmount(r.totals.profit)}</td><td /></tr></tfoot>
              </table>
            </div>)}
            <p className="px-3 pb-2 text-[13px] text-muted">Each line counts where it was tagged; lines with no {label.toLowerCase()} are shown as not tagged. The total is the profit and loss of the period.</p>
            <ReportFooter currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

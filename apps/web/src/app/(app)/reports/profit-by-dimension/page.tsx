import { Card, PageTitle } from "@/components/ui";
import { getProfitByDimension, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Profit by location, class or project" };

const KINDS = [["location", "Location"], ["class", "Class"], ["project", "Project"]] as const;

/** Module 10: income, cost of sales, expenses and profit for each location, class or project, from the tagged ledger lines. */
export default async function ProfitByDimensionPage({ searchParams }: PageProps<"/reports/profit-by-dimension">) {
  const q = await searchParams;
  const kind = KINDS.some(([k]) => k === q.kind) ? (q.kind as string) : "location";
  const period = await getReportPeriod(q);
  const r = await getProfitByDimension({ kind, ...period });
  const label = KINDS.find(([k]) => k === kind)?.[1] ?? "Location";

  return (
    <>
      <PageTitle eyebrow="Reports / Management" title="Profit by location, class or project" actions={<ExportLink report="profit-by-dimension" params={{ kind, ...period }} />} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="kind">By</label>
          <select className="field" id="kind" name="kind" defaultValue={kind}>{KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={period.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={period.to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/profit-by-dimension" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Profit by dimension">
            <div className="px-3 pt-2 text-[13px] text-muted">{periodLabel(r.from, r.to)} · {r.currency} · by {label.toLowerCase()}</div>
            <div className="overflow-x-auto">
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
            </div>
            <p className="px-3 pb-2 text-[13px] text-muted">Each line counts where it was tagged; lines with no {label.toLowerCase()} are shown as not tagged. The total is the profit and loss of the period.</p>
          </Card>
        )}
      </div>
    </>
  );
}

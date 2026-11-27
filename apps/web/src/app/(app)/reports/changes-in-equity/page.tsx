import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { measureLines } from "@/lib/compareLinesMeasure";
import { COMPARE_FIELDS, compareQuery, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { exportPeriod } from "@/lib/periodParams";
import { Card, PageTitle } from "@/components/ui";
import { getChangesInEquity, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { rangeLabel } from "@/lib/periods";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Changes in equity" };

/** Module 10: the statement of changes in equity for a period. */
export default async function ChangesInEquityPage({ searchParams }: PageProps<"/reports/changes-in-equity">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  const cmp = pickRangeComparisons(q, period.picked, period.ctx);
  const diff = pickDiff(q, "pct");
  const own = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [own, ...cmp.periods.map((p) => p.label)];
  const comparedWith = cmp.periods.length ? `Compared with ${cmp.periods.map((p) => p.label).join(", ")}` : null;
  const [soce, ...others] = await Promise.all([period, ...cmp.periods].map((x) => getChangesInEquity({ from: x.from, to: x.to })));
  const lines = cmp.periods.length && soce && others.every(Boolean)
    ? measureLines([soce, ...others] as NonNullable<typeof soce>[], (x) => x.lines, (l) => l.code ?? l.name, (l) => l.closing,
      (l) => `${l.code ? `${l.code} ` : ""}${l.name}`, { label: "Total equity", value: (x) => x.closing })
    : null;

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Changes in equity" actions={<ExportLink report="changes-in-equity" params={{ ...exportPeriod(period.picked), ...compareQuery(cmp.slots, diff) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={COMPARE_FIELDS}>
        <CompareFilter slots={cmp.slots} diff={diff} />
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/changes-in-equity" />
        {!soce ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Statement of changes in equity">
            <ReportHeader title="Statement of changes in equity" period={rangeLabel(period.picked, period.ctx)} filters={[`Profit for the period ${formatAmount(soce.net_profit, soce.currency)}`, lines ? "Equity at the end of each period" : null, comparedWith]} />
            {lines ? <CompareTable first="Equity" lines={lines} headers={headers} diff={diff} /> : (<div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Equity</th><th scope="col" className="!text-right">At the start</th><th scope="col" className="!text-right">Movement</th><th scope="col" className="!text-right">At the end</th></tr></thead>
                <tbody>
                  {soce.lines.map((l) => (
                    <tr key={l.name}>
                      <td>{l.code && <span className="num text-muted">{l.code} </span>}{l.name}</td>
                      <td className="num text-right">{formatAmount(l.opening, soce.currency)}</td><td className="num text-right">{formatAmount(l.movement, soce.currency)}</td>
                      <td className="num text-right">{formatAmount(l.closing, soce.currency)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-ink font-bold"><td>Total equity</td><td className="num text-right">{formatAmount(soce.opening, soce.currency)}</td>
                    <td className="num text-right">{formatAmount(soce.movement, soce.currency)}</td><td className="num text-right">{formatAmount(soce.closing, soce.currency)}</td></tr>
                </tfoot>
              </table>
            </div>)}
            <p className="mt-3 text-[13px] text-muted">Retained earnings are shown with the profit, closed or not, so the total is the same before and after a year-end close.</p>
            <ReportFooter currency={soce.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

import { Card, PageTitle } from "@/components/ui";
import { getChangesInEquity, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { ExportLink, PeriodForm, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Changes in equity" };

/** Module 10: the statement of changes in equity for a period. */
export default async function ChangesInEquityPage({ searchParams }: PageProps<"/reports/changes-in-equity">) {
  const period = await getReportPeriod(await searchParams);
  const soce = await getChangesInEquity(period);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Changes in equity" actions={<ExportLink report="changes-in-equity" params={period} />} />
      <PeriodForm from={period.from} to={period.to} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/changes-in-equity" />
        {!soce ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Statement of changes in equity">
            <div className="mb-3 text-[13px] text-muted">{periodLabel(soce.from, soce.to)} · {soce.currency} · profit for the period {formatAmount(soce.net_profit, soce.currency)}</div>
            <div className="overflow-x-auto">
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
            </div>
            <p className="mt-3 text-[13px] text-muted">Retained earnings are shown with the profit, closed or not, so the total is the same before and after a year-end close.</p>
          </Card>
        )}
      </div>
    </>
  );
}

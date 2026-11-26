import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getCashForecast } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Cash forecast" };

const SPANS = [4, 8, 13, 26] as const;

/**
 * Module 5: the cash forecast, week by week from today: what the bank, cash and wallet accounts hold, what customers
 * owe and what is owed to suppliers by due date (overdue in the first week), the post-dated cheques held, and what the
 * recurring journals and invoices will move on their dates, what confirmed orders will bring in or pay out, and the
 * supplier payments scheduled, on their planned day.
 */
export default async function CashForecastPage({ searchParams }: PageProps<"/reports/cash-forecast">) {
  const { weeks } = await searchParams;
  const span = typeof weeks === "string" && (SPANS as readonly number[]).includes(Number(weeks)) ? Number(weeks) : 13;
  const f = await getCashForecast(span);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash forecast" actions={<ExportLink report="cash-forecast" params={{ weeks: String(span) }} />} />
      <Segmented label="Weeks" size="sm" items={SPANS.map((w) => ({ key: String(w), href: `/reports/cash-forecast?weeks=${w}`, label: `${w} weeks`, active: span === w }))} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/cash-forecast" />
        {!f ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Cash forecast">
            <ReportHeader title="Cash forecast" period={`The next ${span} weeks, from ${f.as_of}`} />
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="text-[13px] text-muted">From {f.as_of} · cash held now <span className="num font-semibold">{formatAmount(f.opening)}</span></div>
              {f.goes_below_zero && f.lowest
                ? <Pill tone="bad">Below zero from the week of {f.lowest.from}</Pill>
                : <Pill tone="good">Stays above zero</Pill>}
            </div>
            {(Number(f.overdue.receivables) > 0 || Number(f.overdue.payables) > 0) && (
              <p className="mb-3 text-[13px] text-muted">
                Overdue, counted in the first week: <span className="num">{formatAmount(f.overdue.receivables)}</span> from customers,{" "}
                <span className="num">{formatAmount(f.overdue.payables)}</span> to suppliers.
              </p>
            )}
            {/* Wider than a laptop screen: a keyboard stop, so it can be scrolled sideways without a mouse. */}
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Cash forecast by week">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Week</th><th scope="col" className="!text-right">From customers</th><th scope="col" className="!text-right">Cheques in</th>
                    <th scope="col" className="!text-right">Recurring in</th><th scope="col" className="!text-right">Sales orders</th>
                    <th scope="col" className="!text-right">To suppliers</th><th scope="col" className="!text-right">Cheques out</th>
                    <th scope="col" className="!text-right">Recurring out</th><th scope="col" className="!text-right">Purchase orders</th>
                    <th scope="col" className="!text-right">Scheduled payments</th>
                    <th scope="col" className="!text-right">Net</th><th scope="col" className="!text-right">Cash at the end</th></tr>
                </thead>
                <tbody>
                  {f.data.map((w) => (
                    <tr key={w.week}>
                      <td className="num">{w.from} – {w.to}</td>
                      <td className="num text-right">{formatAmount(w.receivables)}</td>
                      <td className="num text-right">{formatAmount(w.cheques_in)}</td>
                      <td className="num text-right">{formatAmount(w.recurring_in ?? "0")}</td>
                      <td className="num text-right">{formatAmount(w.orders_in ?? "0")}</td>
                      <td className="num text-right">{formatAmount(w.payables)}</td>
                      <td className="num text-right">{formatAmount(w.cheques_out)}</td>
                      <td className="num text-right">{formatAmount(w.recurring_out ?? "0")}</td>
                      <td className="num text-right">{formatAmount(w.orders_out ?? "0")}</td>
                      <td className="num text-right">{formatAmount(w.scheduled_out ?? "0")}</td>
                      <td className={`num text-right ${Number(w.net) < 0 ? "text-bad" : ""}`}>{formatAmount(w.net)}</td>
                      <td className={`num text-right font-semibold ${Number(w.closing) < 0 ? "text-bad" : ""}`}>{formatAmount(w.closing)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-[13px] text-muted">Read from the books as they stand: posted invoices and bills still owed, by due date, post-dated cheques held, and active recurring entries: a recurring journal&rsquo;s bank and cash lines on each date, a recurring invoice&rsquo;s total by the customer&rsquo;s terms after each date; and confirmed sales and purchase orders, for what is not yet on a posted invoice or bill, by the date wanted plus the contact&rsquo;s terms. A bill with a supplier payment scheduled goes out on the planned day instead of its due date. Drafts are not counted.</p>
            <ReportFooter basis={null} />
          </Card>
        )}
      </div>
    </>
  );
}

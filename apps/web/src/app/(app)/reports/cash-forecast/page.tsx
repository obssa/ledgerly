import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCashForecast } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ReportsNav } from "../ReportsNav";

export const metadata = { title: "Cash forecast" };

const SPANS = [4, 8, 13, 26] as const;

/**
 * Module 5: the cash forecast, week by week from today: what the bank, cash and wallet accounts hold, what customers
 * owe and what is owed to suppliers by due date (overdue in the first week), and the post-dated cheques held.
 */
export default async function CashForecastPage({ searchParams }: PageProps<"/reports/cash-forecast">) {
  const { weeks } = await searchParams;
  const span = typeof weeks === "string" && (SPANS as readonly number[]).includes(Number(weeks)) ? Number(weeks) : 13;
  const f = await getCashForecast(span);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash forecast" />
      <nav aria-label="Weeks" className="mb-3 flex flex-wrap gap-2 text-[13px]">
        {SPANS.map((w) => (
          <Link key={w} href={`/reports/cash-forecast?weeks=${w}`} className={`btn !py-1 ${span === w ? "btn-primary" : ""}`}>{w} weeks</Link>
        ))}
      </nav>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/cash-forecast" />
        {!f ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Cash forecast">
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
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Week</th><th scope="col" className="!text-right">From customers</th><th scope="col" className="!text-right">Cheques in</th>
                    <th scope="col" className="!text-right">To suppliers</th><th scope="col" className="!text-right">Cheques out</th>
                    <th scope="col" className="!text-right">Net</th><th scope="col" className="!text-right">Cash at the end</th></tr>
                </thead>
                <tbody>
                  {f.data.map((w) => (
                    <tr key={w.week}>
                      <td className="num">{w.from} – {w.to}</td>
                      <td className="num text-right">{formatAmount(w.receivables)}</td>
                      <td className="num text-right">{formatAmount(w.cheques_in)}</td>
                      <td className="num text-right">{formatAmount(w.payables)}</td>
                      <td className="num text-right">{formatAmount(w.cheques_out)}</td>
                      <td className={`num text-right ${Number(w.net) < 0 ? "text-bad" : ""}`}>{formatAmount(w.net)}</td>
                      <td className={`num text-right font-semibold ${Number(w.closing) < 0 ? "text-bad" : ""}`}>{formatAmount(w.closing)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-[13px] text-muted">Read from the books as they stand: posted invoices and bills still owed, by due date, and post-dated cheques held. Drafts, orders and recurring entries are not counted.</p>
          </Card>
        )}
      </div>
    </>
  );
}

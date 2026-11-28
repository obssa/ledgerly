import Link from "@/components/Link";
import { Dt } from "@/components/Dt";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { Card, PageTitle } from "@/components/ui";
import { getRealizedGains, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { exportPeriod } from "@/lib/periodParams";
import { rangeLabel } from "@/lib/periods";
import { typeLabel } from "@/lib/transactionColumns";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Realized gains and losses" };

/**
 * QuickBooks' Realized Gains and Losses: every exchange difference booked when foreign money actually moved (a payment
 * at a rate other than its invoice's or bill's, a credit applied later, a refund, a write-off, a currency transfer), with
 * its transaction. A gain is positive; month-end revaluations are unrealized and left out.
 */
export default async function RealizedGainsPage({ searchParams }: PageProps<"/reports/exchange-gains">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "this_fiscal_year_to_date");
  const r = await getRealizedGains({ from: period.from, to: period.to });
  const what = (l: NonNullable<typeof r>["lines"][number]) =>
    l.payment_id ? (l.direction === "in" ? "Payment received" : "Payment made") : l.document_type ? typeLabel(l.document_type) : typeLabel(l.source_type);
  const href = (l: NonNullable<typeof r>["lines"][number]) =>
    l.payment_id ? `/${l.direction === "in" ? "payments" : "payments-made"}/${encodeURIComponent(l.payment_id)}` : `/journal/${encodeURIComponent(l.entry_id)}`;

  return (
    <>
      <PageTitle title="Realized gains and losses" actions={<ExportLink report="realized-gains" params={{ ...exportPeriod(period.picked) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/exchange-gains" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Realized gains and losses">
            <div className="px-3 pt-3"><ReportHeader title="Realized gains and losses" period={rangeLabel(period.picked, period.ctx)} filters={["A gain is positive, a loss negative"]} /></div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Date</th><th scope="col">Transaction type</th><th scope="col">No.</th><th scope="col">Name</th><th scope="col">Currency</th>
                  <th scope="col" className="!text-right">Foreign amount</th><th scope="col" className="!text-right">Exchange rate</th><th scope="col" className="!text-right">Gain or loss ({r.currency})</th></tr></thead>
                <tbody>
                  {r.lines.length === 0 && <tr><td colSpan={8} className="text-muted">No exchange gain or loss booked in the period.</td></tr>}
                  {r.lines.map((l, i) => (
                    <tr key={`${l.entry_id}-${i}`}>
                      <td className="num"><Dt v={l.date} /></td>
                      <td>{what(l)}</td>
                      <td className="num"><Link href={href(l)} className="hover:underline">{l.document_number ?? l.reference ?? `#${l.entry}`}</Link></td>
                      <td>{l.name ?? "—"}</td>
                      <td>{l.currency ?? "—"}</td>
                      <td className="num text-right">{l.foreign_amount ? formatAmount(l.foreign_amount) : "—"}</td>
                      <td className="num text-right text-muted">{l.exchange_rate ?? "—"}</td>
                      <td className={`num text-right font-semibold ${l.gain.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(l.gain)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={7}>Total realized {r.total.startsWith("-") ? "loss" : "gain"}</td><td className="num text-right">{formatAmount(r.total)}</td></tr></tfoot>
              </table>
            </div>
            <p className="px-3 pb-2 text-[13px] text-muted">Booked to the exchange gains and losses account when the money moved. What open foreign balances would gain or lose at today&apos;s rate is in <Link href="/reports/exchange-gains/unrealized" className="font-semibold text-brand hover:underline">Unrealized gains and losses</Link>.</p>
            <ReportFooter basis={null} currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

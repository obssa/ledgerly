import Link from "@/components/Link";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { Card, PageTitle } from "@/components/ui";
import { getReportAsOf, getUnrealizedGains } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { asOfLabel } from "@/lib/periods";
import { ExportLink, ReportsNav } from "../../ReportsNav";

export const metadata = { title: "Unrealized gains and losses" };

const KIND: Record<string, string> = { account: "Bank or wallet", invoice: "Invoice", credit_note: "Credit note", bill: "Bill", vendor_credit: "Supplier credit",
  customer_credit: "Customer credit not yet applied", supplier_advance: "Advance to a supplier not yet applied" };

/**
 * QuickBooks' Unrealized Gains and Losses: at a date, every open foreign item (a foreign bank or wallet balance, an open
 * invoice, credit note, bill or supplier credit, a payment not yet applied), its value in the books, its value at that
 * day's rate, and the difference. Nothing is posted here: Revalue foreign balances books it at a month end.
 */
export default async function UnrealizedGainsPage({ searchParams }: PageProps<"/reports/exchange-gains/unrealized">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const r = await getUnrealizedGains(at.asOf);

  return (
    <>
      <PageTitle eyebrow="Reports / Foreign currency" title="Unrealized gains and losses"
        actions={<><Link href={`/revaluation?as_of=${encodeURIComponent(at.asOf)}`} className="btn">Revalue foreign balances</Link><ExportLink report="unrealized-gains" params={{ as_of: at.asOf }} /></>} />
      <AsOfFilter picked={at.picked} keep={q} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/exchange-gains/unrealized" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Unrealized gains and losses">
            <div className="px-3 pt-3"><ReportHeader title="Unrealized gains and losses" period={asOfLabel(at.asOf, at.ctx)}
              filters={[Object.entries(r.rates).filter(([, v]) => v).map(([c, v]) => `${c} at ${v}`).join(", ") || null]} /></div>
            {r.missing_rates.length > 0 && (
              <p role="status" className="mx-3 mb-2 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
                No rate in the week before this date for {r.missing_rates.join(", ")}: enter one in Settings, Currencies to value {r.missing_rates.length === 1 ? "it" : "them"}.
              </p>
            )}
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Item</th><th scope="col">Name</th><th scope="col">Currency</th><th scope="col" className="!text-right">Foreign open balance</th>
                  <th scope="col" className="!text-right">In the books ({r.currency})</th><th scope="col" className="!text-right">Rate</th>
                  <th scope="col" className="!text-right">At the rate ({r.currency})</th><th scope="col" className="!text-right">Unrealized gain or loss</th></tr></thead>
                <tbody>
                  {r.lines.length === 0 && <tr><td colSpan={8} className="text-muted">No open foreign balances at this date.</td></tr>}
                  {r.lines.map((l, i) => (
                    <tr key={`${l.kind}-${l.document_id ?? l.payment_id ?? l.account_id}-${i}`}>
                      <td>{KIND[l.kind] ?? l.kind}<span className="block text-[12px] text-muted">{l.label}</span></td>
                      <td>{l.name ?? "—"}</td>
                      <td>{l.currency}</td>
                      <td className="num text-right">{formatAmount(l.amount)}</td>
                      <td className="num text-right">{formatAmount(l.booked)}</td>
                      <td className="num text-right text-muted">{l.rate ?? "no rate"}</td>
                      <td className="num text-right">{l.restated ? formatAmount(l.restated) : "—"}</td>
                      <td className={`num text-right font-semibold ${l.difference?.startsWith("-") ? "text-bad" : ""}`}>{l.difference ? formatAmount(l.difference) : "—"}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={7}>Net unrealized {r.net.startsWith("-") ? "loss" : "gain"}</td><td className="num text-right">{formatAmount(r.net)}</td></tr></tfoot>
              </table>
            </div>
            <p className="px-3 pb-2 text-[13px] text-muted">In the ledger&apos;s terms: a bank balance or what a customer owes gains as the rate rises; what is owed to a supplier, or a customer&apos;s credit, costs more. Nothing is booked here.</p>
            <ReportFooter basis={null} currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { asOfLabel, resolveRange } from "@/lib/periods";
import { getTrialBalance, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink } from "../ReportsNav";
import { trialTotals } from "@/lib/ledger";

export const metadata = { title: "Trial balance" };

/** Module 6: every account's balance at a date, debits against credits; each account opens its general ledger. */
export default async function TrialBalancePage({ searchParams }: PageProps<"/reports/trial-balance">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const rows = await getTrialBalance(asOf);
  const totals = trialTotals(rows ?? []);
  // Each account opens its ledger for the fiscal year up to this date.
  const from = resolveRange("this_fiscal_year", { ...at.ctx, today: asOf })?.from ?? asOf;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Ledger</>}
        title={<>Trial balance {rows && (totals.balanced ? <Pill tone="good">Balanced</Pill> : <Pill tone="bad">Out of balance</Pill>)}</>}
        actions={<ExportLink report="trial-balance" params={{ as_of: asOf }} />} />
      <AsOfFilter picked={at.picked} keep={q} />
      {!rows ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card className="!p-2" label="Trial balance">
          <div className="px-3 pt-3"><ReportHeader title="Trial balance" period={asOfLabel(asOf, at.ctx)} /></div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></tr></thead>
              <tbody>
                {rows.length === 0 && <tr><td colSpan={4} className="text-muted">Nothing posted yet.</td></tr>}
                {rows.map((r) => (
                  <tr key={r.account_id}>
                    <td className="num">{r.code}</td>
                    <td><Link href={`/reports/general-ledger?account_id=${encodeURIComponent(r.account_id)}&from=${from}&to=${asOf}`} className="hover:underline">{r.name}</Link></td>
                    <td className="num text-right">{r.debit === "0.00" ? "" : formatAmount(r.debit)}</td>
                    <td className="num text-right">{r.credit === "0.00" ? "" : formatAmount(r.credit)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={2}>Total</td><td className="num text-right">{formatAmount(totals.debit)}</td><td className="num text-right">{formatAmount(totals.credit)}</td></tr></tfoot>
            </table>
          </div>
          <ReportFooter />
        </Card>
      )}
    </>
  );
}

import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getTrialBalance, getCompanyToday } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink } from "../ReportsNav";
import { defaultPeriod, trialTotals } from "@/lib/ledger";

export const metadata = { title: "Trial balance" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** Module 6: every account's balance at a date, debits against credits; each account opens its general ledger. */
export default async function TrialBalancePage({ searchParams }: PageProps<"/reports/trial-balance">) {
  const asked = date((await searchParams).as_of);   // read the request first: the date below is then per request
  const asOf = asked ?? (await getCompanyToday());
  const rows = await getTrialBalance(asOf);
  const totals = trialTotals(rows ?? []);
  const from = defaultPeriod(asOf).from;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Ledger</>}
        title={<>Trial balance {rows && (totals.balanced ? <Pill tone="good">Balanced</Pill> : <Pill tone="bad">Out of balance</Pill>)}</>}
        actions={<ExportLink report="trial-balance" params={{ as_of: asOf }} />} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="as_of">As at</label><input className="field" id="as_of" name="as_of" type="date" defaultValue={asOf} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      {!rows ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card className="!p-2" label="Trial balance">
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
        </Card>
      )}
    </>
  );
}

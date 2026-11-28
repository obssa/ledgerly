import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getAdjustedTrialBalance, getJournalOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { journalAccounts } from "@/lib/journal";
import { AdjustmentForm, FinaliseAudit } from "../../CloseForm";

export const metadata = { title: "Audit adjustments" };

/** Debit balances as they are; credit balances in brackets. */
const signed = (v: string) => (v === "0.00" ? "—" : v.startsWith("-") ? `(${formatAmount(v.slice(1))})` : formatAmount(v));

/** Module 6: the adjustments period for the auditor. The worksheet of a closed year, its audit adjustments, and signing off. */
export default async function AuditAdjustmentsPage({ params }: PageProps<"/close/years/[id]">) {
  const { id } = await params;
  const [tb, options] = await Promise.all([getAdjustedTrialBalance(id), getJournalOptions()]);
  if (!tb) notFound();
  const { year } = tb;

  return (
    <>
      <PageTitle crumbs={<>Audit</>}
        title={<>Year to <span className="num"><Dt v={year.ends_on} /></span> {year.adjustments_closed_at ? <Pill tone="good">Audit signed off</Pill> : year.status === "closed" ? <Pill tone="warn">Open for audit adjustments</Pill> : <Pill>Not closed</Pill>}</>} />
      <Card className="mb-4 !p-2" label="Adjusted trial balance">
        <h2 className="px-3 pt-2 text-[15px] font-bold">Adjusted trial balance at <span className="num"><Dt v={year.ends_on} /></span>, before the close</h2>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Account</th><th scope="col" className="!text-right">Unadjusted</th><th scope="col" className="!text-right">Adjustments</th><th scope="col" className="!text-right">Adjusted</th></tr>
            </thead>
            <tbody>
              {tb.lines.length === 0 && <tr><td colSpan={4} className="text-muted">Nothing was posted in the year.</td></tr>}
              {tb.lines.map((l) => (
                <tr key={l.account_id}>
                  <td><span className="num">{l.code}</span> {l.name}</td>
                  <td className="num text-right">{signed(l.unadjusted)}</td>
                  <td className={`num text-right ${l.adjustments !== "0.00" ? "font-semibold" : ""}`}>{signed(l.adjustments)}</td>
                  <td className="num text-right">{signed(l.adjusted)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-ink font-bold">
                <td>Total (nothing when balanced)</td>
                <td className="num text-right">{signed(tb.totals.unadjusted)}</td><td className="num text-right">{signed(tb.totals.adjustments)}</td><td className="num text-right">{signed(tb.totals.adjusted)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p className="px-3 pb-2 text-[13px] text-muted">Debits as they are, credits in brackets. Closing entries are left out, so income and expenses show the year as it ran.</p>
      </Card>
      <Card className="mb-4 !p-2" label="Audit adjustments">
        <h2 className="px-3 pt-2 text-[15px] font-bold">Audit adjustments ({tb.adjustments.length})</h2>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Entry</th><th scope="col">Memo</th><th scope="col">Posted by</th></tr></thead>
            <tbody>
              {tb.adjustments.length === 0 && <tr><td colSpan={3} className="text-muted">None yet.</td></tr>}
              {tb.adjustments.map((e) => (
                <tr key={e.id}>
                  <td className="num font-semibold"><Link href={`/journal/${encodeURIComponent(e.id)}`} className="hover:underline">#{e.number}</Link></td>
                  <td>{e.memo ?? "—"}</td>
                  <td>{e.posted_by ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {tb.can.adjust && options && (
        <>
          <Card className="mb-4" label="Post an adjustment">
            <h2 className="mb-3 text-[15px] font-bold">Post an adjustment</h2>
            <AdjustmentForm id={year.id} endsOn={year.ends_on} accounts={journalAccounts(options.accounts, options.baseCurrency)} dimensions={options.dimensions} />
          </Card>
          <Card className="max-w-[900px]" label="Sign off">
            <h2 className="mb-3 text-[15px] font-bold">Sign the audit off</h2>
            <FinaliseAudit id={year.id} />
          </Card>
        </>
      )}
      {!tb.can.adjust && year.status === "closed" && !year.adjustments_closed_at && (
        <p className="text-[13px] text-muted">An owner or admin posts audit adjustments and signs the audit off. Only the latest closed year takes adjustments.</p>
      )}
    </>
  );
}

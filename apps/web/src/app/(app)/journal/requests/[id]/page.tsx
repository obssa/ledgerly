import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getJournalRequest } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { DecideRequest } from "../../JournalForms";

export const metadata = { title: "Journal waiting for approval" };

const STATUS = { pending: ["Waiting for approval", "warn"], approved: ["Approved", "good"], rejected: ["Rejected", "bad"], withdrawn: ["Withdrawn", "neutral"] } as const;

/** Module 6: a manual journal above the approval limit, and its decision. */
export default async function JournalRequestPage({ params }: PageProps<"/journal/requests/[id]">) {
  const { id } = await params;
  const r = await getJournalRequest(id);
  if (!r) notFound();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal" className="hover:underline">Journal entries</Link> / Approval</>}
        title={<>{r.memo} <Pill tone={STATUS[r.status][1]}>{STATUS[r.status][0]}</Pill></>} />
      <Card className="mb-4" label="Request">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Date of the entry</dt><dd className="num"><Dt v={r.date} /></dd></div>
          <div><dt className="text-muted">Amount</dt><dd className="num font-semibold">{formatAmount(r.total)}</dd></div>
          <div><dt className="text-muted">Asked by</dt><dd>{r.requested_by ?? "—"}</dd></div>
          {r.reverse_on && <div><dt className="text-muted">Reverses on</dt><dd className="num"><Dt v={r.reverse_on} /></dd></div>}
          {r.decided_by && <div><dt className="text-muted">{r.status === "withdrawn" ? "Withdrawn by" : "Decided by"}</dt><dd>{r.decided_by}</dd></div>}
          {r.journal_entry && <div><dt className="text-muted">Posted as</dt><dd><Link href={`/journal/${encodeURIComponent(r.journal_entry.id)}`} className="num hover:underline">#{r.journal_entry.number}</Link></dd></div>}
        </dl>
        {r.note && <p className="mt-3 text-[14px]"><span className="font-semibold">Why rejected:</span> {r.note}</p>}
      </Card>
      <Card className="mb-4 !p-2" label="Lines">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Account</th><th scope="col">Description</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></tr></thead>
            <tbody>
              {(r.lines ?? []).map((l, i) => (
                <tr key={i}>
                  <td><span className="num">{l.code}</span> {l.account}</td>
                  <td>{l.description ?? ""}</td>
                  <td className="num text-right">{l.debit ? formatAmount(l.debit) : ""}</td>
                  <td className="num text-right">{l.credit ? formatAmount(l.credit) : ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {(r.can.approve || r.can.withdraw) && (
        <Card className="max-w-[900px]" label="Decide">
          <DecideRequest id={r.id} canApprove={r.can.approve} canWithdraw={r.can.withdraw} />
        </Card>
      )}
      {r.status === "pending" && !r.can.approve && !r.can.withdraw && (
        <p className="text-[13px] text-muted">Waiting for someone other than {r.requested_by ?? "the person who asked"} to approve it.</p>
      )}
    </>
  );
}

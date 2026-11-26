import Link from "@/components/Link";
import { AccountTabs } from "../AccountTabs";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getReconciliations, getCompanyToday } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { StartReconciliation } from "../../ReconcileForms";

export const metadata = { title: "Reconcile" };

const STATUS = { draft: ["In progress", "warn"], completed: ["Reconciled", "good"], undone: ["Undone", "neutral"] } as const;

/** Module 5: a money account's reconciliations, and the start of the next one. */
export default async function ReconcilePage({ params }: PageProps<"/banking/[id]/reconcile">) {
  const { id } = await params;
  const list = await getReconciliations(id);
  if (!list) notFound();
  const account = list.account;
  const href = (rid: string) => `/banking/${encodeURIComponent(id)}/reconcile/${encodeURIComponent(rid)}`;
  const today = await getCompanyToday();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/banking" className="hover:underline">Banking and cash</Link> / <Link href={`/banking/${encodeURIComponent(id)}`} className="hover:underline">
          <span className="num">{account.code}</span> {account.name}</Link> / Reconcile</>}
        title="Reconcile"
      />
      <AccountTabs id={id} current="reconcile" />
      <p className="mb-4 text-[14px] text-muted">
        {list.reconciled_through ? <>Reconciled through <span className="num font-semibold text-ink">{list.reconciled_through}</span>; entries on or before that date are locked.</> : "Not reconciled yet."}
      </p>
      {list.draft_id ? (
        <Card className="mb-4" label="In progress">
          <p className="text-[14px]">A reconciliation is in progress. <Link href={href(list.draft_id)} className="font-semibold text-brand hover:underline">Carry on with it</Link>.</p>
        </Card>
      ) : list.can.reconcile && (
        <Card className="mb-4 max-w-[900px]" label="Start">
          <h2 className="mb-2 text-[15px] font-bold">Reconcile a statement</h2>
          <StartReconciliation accountId={id} opening={list.next_opening} today={today} />
        </Card>
      )}
      <Card className="!p-2" label="History">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Statement ends</th><th scope="col">Status</th><th scope="col" className="!text-right">Opening</th><th scope="col" className="!text-right">Ending ({account.currency})</th><th scope="col">Note</th></tr></thead>
            <tbody>
              {list.data.length === 0 && <tr><td colSpan={5} className="text-muted">No reconciliations yet.</td></tr>}
              {list.data.map((r) => (
                <tr key={r.id}>
                  <td className="num font-semibold"><Link href={href(r.id)} className="hover:underline">{r.statement_date}</Link></td>
                  <td><Pill tone={STATUS[r.status][1]}>{STATUS[r.status][0]}</Pill></td>
                  <td className="num text-right">{formatAmount(r.opening_balance)}</td>
                  <td className="num text-right">{formatAmount(r.ending_balance)}</td>
                  <td className="text-[13px] text-muted">{r.undo_reason ?? ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

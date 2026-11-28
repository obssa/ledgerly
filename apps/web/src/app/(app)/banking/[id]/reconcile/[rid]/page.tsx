import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { ReconciliationLine } from "@/lib/api";
import { getReconciliation } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { DiscardReconciliation, ReconcileWorkspace, UndoReconciliation } from "../../../ReconcileForms";

export const metadata = { title: "Reconciliation" };

function Lines({ lines, empty }: { lines: ReconciliationLine[]; empty: string }) {
  return (
    <div className="overflow-x-auto">
      <table className="tbl">
        <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">What</th><th scope="col">Who</th><th scope="col" className="!text-right">Amount</th></tr></thead>
        <tbody>
          {lines.length === 0 && <tr><td colSpan={5} className="text-muted">{empty}</td></tr>}
          {lines.map((l, i) => (
            <tr key={`${l.entry}-${i}`}>
              <td className="num"><Dt v={l.date} /></td><td className="num">#{l.entry}</td><td>{l.memo ?? l.source_type}</td><td>{l.contact ?? "—"}</td>
              <td className="num text-right">{formatAmount(l.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** One reconciliation: the workspace while in progress, the saved report once finished (and how to undo it). */
export default async function ReconciliationPage({ params }: PageProps<"/banking/[id]/reconcile/[rid]">) {
  const { id, rid } = await params;
  const r = await getReconciliation(rid);
  if (!r || r.account.id !== id) notFound();
  const back = `/banking/${encodeURIComponent(id)}/reconcile`;

  return (
    <>
      <PageTitle
        crumbs={<><Link href={back} className="hover:underline">
          <span className="num">{r.account.code}</span> {r.account.name}: reconcile</Link></>}
        title={<>Statement to <span className="num"><Dt v={r.statement_date} /></span> {r.status === "completed" ? <Pill tone="good">Reconciled</Pill> : r.status === "undone" ? <Pill>Undone</Pill> : <Pill tone="warn">In progress</Pill>}</>}
      />
      {r.status === "draft" ? (
        <>
          <Card className="!p-4" label="Workspace"><ReconcileWorkspace r={r} /></Card>
          {r.can.reconcile && <Card className="mt-4 max-w-[900px]" label="Discard"><h2 className="mb-2 text-[15px] font-bold">Discard this reconciliation</h2><DiscardReconciliation id={r.id} accountId={id} /></Card>}
        </>
      ) : (
        <>
          <Card className="mb-4" label="Report">
            <h2 className="mb-3 text-[15px] font-bold">Reconciliation report</h2>
            <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-[14px]">
              <div><dt className="text-muted">Opening balance</dt><dd className="num font-semibold">{formatAmount(r.opening ?? r.opening_balance)}</dd></div>
              <div><dt className="text-muted">Cleared in</dt><dd className="num font-semibold">{formatAmount(r.cleared_in ?? "0.00")}</dd></div>
              <div><dt className="text-muted">Cleared out</dt><dd className="num font-semibold">{formatAmount(r.cleared_out ?? "0.00")}</dd></div>
              <div><dt className="text-muted">Statement ending balance</dt><dd className="num font-semibold">{formatAmount(r.ending ?? r.ending_balance)}</dd></div>
              <div><dt className="text-muted">Not yet on the statement</dt><dd className="num font-semibold">{formatAmount(r.outstanding_total ?? "0.00")}</dd></div>
              <div><dt className="text-muted">Balance in the books</dt><dd className="num font-semibold">{formatAmount(r.book_balance ?? "0.00")}</dd></div>
            </dl>
            {r.status === "undone" && <p className="mt-3 text-[13px] text-muted">Undone{r.undone_at ? <> on <Dt v={r.undone_at} /></> : ""}: {r.undo_reason}</p>}
          </Card>
          <Card className="mb-4 !p-2" label="Cleared"><h2 className="px-3 pt-2 text-[15px] font-bold">Cleared ({r.cleared?.length ?? 0})</h2><Lines lines={r.cleared ?? []} empty="Nothing cleared." /></Card>
          <Card className="mb-4 !p-2" label="Outstanding"><h2 className="px-3 pt-2 text-[15px] font-bold">In the books, not yet on the statement ({r.outstanding?.length ?? 0})</h2><Lines lines={r.outstanding ?? []} empty="Nothing outstanding." /></Card>
          {r.status === "completed" && r.can.reconcile && (
            <Card className="max-w-[900px]" label="Undo">
              <h2 className="mb-1 text-[15px] font-bold">Undo this reconciliation</h2>
              <p className="mb-3 text-[13px] text-muted">Only the latest reconciliation can be undone. Its entries can then be ticked again and the period opens; the reason is kept in the audit log.</p>
              <UndoReconciliation id={r.id} />
            </Card>
          )}
        </>
      )}
    </>
  );
}

import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getRequisition } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { REQUISITION_STATUS } from "../status";
import { DecideRequisition, OrderRequisition, WithdrawRequisition } from "../RequisitionForms";

export const metadata = { title: "Purchase requisition" };

/** One purchase requisition (084): what was asked for, the decision, and the order it became. */
export default async function RequisitionPage({ params }: PageProps<"/purchase-requisitions/[id]">) {
  const { id } = await params;
  const r = await getRequisition(id);
  if (!r) notFound();
  const status = REQUISITION_STATUS[r.status] ?? { label: r.status, tone: "neutral" as const };
  return (
    <>
      <PageTitle eyebrow={<Link href="/purchase-requisitions" className="hover:underline">Purchase requisitions</Link>} title={<>{r.reason} <Pill tone={status.tone}>{status.label}</Pill></>} />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="What was asked for">
          <p className="mb-3 text-sm text-muted">Asked by {r.requested_by_name} on <span className="num">{r.created_at.slice(0, 10)}</span>{r.needed_by && <>, needed by <span className="num">{r.needed_by}</span></>}.</p>
          <table className="tbl">
            <thead><tr><th scope="col">What</th><th scope="col" className="!text-right">How many</th><th scope="col" className="!text-right">About, each</th></tr></thead>
            <tbody>
              {r.lines.map((l, i) => (
                <tr key={i}><td>{l.description}</td><td className="num text-right">{l.quantity}</td><td className="num text-right">{l.estimated_price ? formatAmount(l.estimated_price) : "—"}</td></tr>
              ))}
            </tbody>
          </table>
          {r.decided_by_name && (
            <p className="mt-4 text-sm">{r.status === "rejected" ? "Rejected" : "Approved"} by {r.decided_by_name}{r.decision_note ? `: ${r.decision_note}` : "."}</p>
          )}
          {r.purchase_order_id && (
            <p className="mt-2 text-sm">Ordered on <Link href={`/purchase-orders/${encodeURIComponent(r.purchase_order_id)}`} className="font-semibold underline">{r.purchase_order_number ?? "the draft purchase order"}</Link>.</p>
          )}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {r.can.decide && <Card label="Decide"><h2 className="mb-2 text-[17px] font-bold">Decide</h2><DecideRequisition id={r.id} /></Card>}
          {r.can.order && <Card label="Order"><h2 className="mb-2 text-[17px] font-bold">Order it</h2><OrderRequisition r={r} /></Card>}
          {r.can.withdraw && <WithdrawRequisition id={r.id} />}
          {r.status === "requested" && !r.can.decide && <p className="text-sm text-muted">Waiting for someone who approves bills.</p>}
        </aside>
      </div>
    </>
  );
}

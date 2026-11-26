import Link from "@/components/Link";
import { redirect } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getRequisitions } from "@/lib/data";
import { REQUISITION_STATUS } from "./status";

export const metadata = { title: "Purchase requisitions" };

/** Module 3, purchase requisitions (084): one's own requests, or everyone's for those who decide or order. */
export default async function RequisitionsPage() {
  const list = await getRequisitions();
  if (!list) redirect("/no-access");
  return (
    <>
      <PageTitle eyebrow="Money out" title="Purchase requisitions" actions={<Link href="/purchase-requisitions/new" className="btn btn-primary">+ Ask for something</Link>} />
      <Card className="max-w-[1000px]" label="Purchase requisitions">
        <p className="mb-3 text-sm text-muted">
          Ask for something to be bought; someone who approves bills decides, and an approved request becomes a purchase order to a supplier.
          {list.can.see_all ? " You see every request." : " You see your own requests."}
        </p>
        {list.data.length === 0 ? <p className="text-sm text-muted">No requests yet.</p> : (
          <table className="tbl">
            <thead><tr><th scope="col">Asked</th><th scope="col">By</th><th scope="col">For</th><th scope="col">Needed by</th><th scope="col">Status</th></tr></thead>
            <tbody>
              {list.data.map((r) => (
                <tr key={r.id}>
                  <td className="num"><Link href={`/purchase-requisitions/${encodeURIComponent(r.id)}`} className="font-semibold hover:underline">{r.created_at.slice(0, 10)}</Link></td>
                  <td>{r.requested_by_name}</td>
                  <td>{r.reason} <span className="text-[12px] text-muted">({r.lines} line{r.lines === 1 ? "" : "s"})</span></td>
                  <td className="num">{r.needed_by ?? "—"}</td>
                  <td><Pill tone={REQUISITION_STATUS[r.status]?.tone ?? "neutral"}>{REQUISITION_STATUS[r.status]?.label ?? r.status}</Pill></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </>
  );
}

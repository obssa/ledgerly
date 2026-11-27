import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getDeliveryNotes, getReportPeriod } from "@/lib/data";

export const metadata = { title: "Delivery notes" };

/** Module 2: the delivery notes (071), newest first. Each is recorded from its sales order. */
export default async function DeliveryNotesPage({ searchParams }: PageProps<"/delivery-notes">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const rows = await getDeliveryNotes(listQuery(q, period.picked));
  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Delivery notes</>}
        title="Delivery notes"
        actions={<Link href="/delivery-notes/uninvoiced" className="btn">Delivered, not invoiced</Link>}
      />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Delivery notes">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">No delivery notes yet. When goods leave for a customer, open their sales order and record what went: it leaves stock at its cost.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Date</th><th scope="col">No.</th><th scope="col">Customer</th><th scope="col">Sales order</th>
                  <th scope="col">Waybill no.</th><th scope="col">Warehouse</th><th scope="col">Status</th></tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td className="num"><Dt v={r.delivered_on} /></td>
                    <td className="num font-semibold"><Link href={`/delivery-notes/${encodeURIComponent(r.id)}`} className="hover:underline">{r.number}</Link></td>
                    <td>{r.customer}</td>
                    <td className="num"><Link href={`/sales-orders/${encodeURIComponent(r.sales_order_id)}`} className="hover:underline">{r.order_number ?? "—"}</Link></td>
                    <td className="num">{r.reference ?? "—"}</td>
                    <td>{r.warehouse}</td>
                    <td>{r.status === "void" ? <Pill>Void</Pill> : <Pill tone="good">Delivered</Pill>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <ListPager path="/delivery-notes" picked={period.picked} next={rows.next} prev={rows.prev} />
    </>
  );
}

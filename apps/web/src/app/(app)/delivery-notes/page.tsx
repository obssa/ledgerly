import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getDeliveryNotes } from "@/lib/data";

export const metadata = { title: "Delivery notes" };

/** Module 2: the delivery notes (071), newest first. Each is recorded from its sales order. */
export default async function DeliveryNotesPage() {
  const rows = await getDeliveryNotes();
  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Delivery notes</>}
        title="Delivery notes"
        actions={<Link href="/delivery-notes/uninvoiced" className="btn">Delivered, not invoiced</Link>}
      />
      <Card className="!p-2" label="Delivery notes">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">No delivery notes yet. When goods leave for a customer, open their sales order and record what went: it leaves stock at its cost.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Number</th><th scope="col">Delivered</th><th scope="col">Customer</th><th scope="col">Sales order</th>
                  <th scope="col">Waybill</th><th scope="col">From</th><th scope="col">Status</th></tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td className="num font-semibold"><Link href={`/delivery-notes/${encodeURIComponent(r.id)}`} className="hover:underline">{r.number}</Link></td>
                    <td className="num">{r.delivered_on}</td>
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
    </>
  );
}

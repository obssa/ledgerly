import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getDeliveryNote } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { VoidDeliveryNote } from "./VoidDeliveryNote";

export const metadata = { title: "Delivery note" };

/** One delivery note (071): what left, its cost of goods sold, and its void while its goods are not invoiced. */
export default async function DeliveryNotePage({ params }: PageProps<"/delivery-notes/[id]">) {
  const { id } = await params;
  const n = await getDeliveryNote(id);
  if (!n) notFound();

  return (
    <>
      <PageTitle
        crumbs={<><Link href="/delivery-notes" className="hover:underline">Delivery notes</Link> › {n.number}</>}
        title={<>Delivery note <span className="num">{n.number}</span> {n.status === "void" ? <Pill>Void</Pill> : <Pill tone="good">Delivered</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Delivery note">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Customer</div>
              <Link href={`/customers/${encodeURIComponent(n.contact_id)}`} className="font-bold hover:underline">{n.customer}</Link>
              <div className="mt-1 text-[13px]">Sales order{" "}
                <Link href={`/sales-orders/${encodeURIComponent(n.sales_order_id)}`} className="num font-semibold hover:underline">{n.order_number ?? "—"}</Link></div>
              <div className="text-[13px]">Waybill or their order <span className="num font-semibold">{n.reference ?? "—"}</span></div>
            </div>
            <div className="text-right">
              <div><span className="text-muted">Delivered</span> <span className="num"><Dt v={n.delivered_on} /></span></div>
              <div><span className="text-muted">From</span> {n.warehouse}</div>
            </div>
          </div>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Order line</th><th scope="col">Item</th><th scope="col" className="!text-right">Quantity</th></tr>
              </thead>
              <tbody>
                {n.lines.map((l) => (
                  <tr key={l.id}>
                    <td>{l.line_no}. {l.description}</td>
                    <td>{l.item_name}</td>
                    <td className="num text-right">{l.quantity} {l.unit ?? ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-4 text-sm"><span className="text-muted">Out of stock at their cost, as cost of goods sold:</span> <span className="num font-semibold">{formatAmount(n.cost)}</span></p>
          {n.note && <p className="mt-2 whitespace-pre-line text-sm text-muted">{n.note}</p>}
          {n.status === "void" && <p className="mt-2 text-[13px] text-muted">Voided{n.void_reason ? `: ${n.void_reason}` : ""}. Its goods came back into stock.</p>}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {n.can.void && <VoidDeliveryNote id={n.id} />}
          {n.journal_entry_id && <Link href={`/journal/${encodeURIComponent(n.journal_entry_id)}`} className="btn justify-center">See its journal entry</Link>}
        </aside>
      </div>
    </>
  );
}

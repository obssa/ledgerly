import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getOpenPurchaseOrders } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Still to come from suppliers" };

/** Module 3: what the issued purchase orders still wait for, line by line, the earliest expected first (068). */
export default async function OpenPurchaseOrdersPage() {
  const open = await getOpenPurchaseOrders();

  return (
    <>
      <PageTitle
        crumbs={<><Link href="/purchase-orders" className="hover:underline">Purchase orders</Link> › Still to come</>}
        title="Still to come from suppliers"
      />
      {open === null ? <Card><p className="text-sm text-muted">Not available in the demo.</p></Card> : (
        <Card className="!p-2" label="Open purchase orders">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr>
                  <th scope="col">Order</th><th scope="col">Supplier</th><th scope="col">Expected by</th><th scope="col">Line</th>
                  <th scope="col" className="!text-right">Ordered</th><th scope="col" className="!text-right">Billed</th><th scope="col" className="!text-right">To come</th>
                  <th scope="col" className="!text-right">On hand</th>
                </tr>
              </thead>
              <tbody>
                {open.data.length === 0 && <tr><td colSpan={8} className="text-muted">Nothing is waiting: every issued order is billed.</td></tr>}
                {open.data.map((l) => {
                  const stock = l.item_id ? open.stock[l.item_id] : undefined;
                  return (
                    <tr key={l.line_id}>
                      <td><Link href={`/purchase-orders/${encodeURIComponent(l.order_id)}`} className="num font-semibold hover:underline">{l.number ?? "draft"}</Link></td>
                      <td><Link href={`/suppliers/${encodeURIComponent(l.contact_id)}`} className="hover:underline">{l.customer}</Link></td>
                      <td className="num"><Dt v={l.due_date} /></td>
                      <td>{l.item_name ?? l.description}{l.sku && <span className="num ml-1 text-[12px] text-muted">{l.sku}</span>}</td>
                      <td className="num text-right">{formatAmount(l.ordered)}</td>
                      <td className="num text-right">{formatAmount(l.delivered)}</td>
                      <td className="num text-right font-semibold">{formatAmount(l.remaining)}</td>
                      <td className="num text-right">{stock ? formatAmount(stock.on_hand) : "—"}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  );
}

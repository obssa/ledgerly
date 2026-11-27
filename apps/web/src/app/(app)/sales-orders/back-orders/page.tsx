import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getBackOrders } from "@/lib/data";
import { parse } from "@/lib/decimal";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Back-orders" };

/**
 * Module 2: what the confirmed sales orders still owe their customers, line by line, the earliest wanted first (067).
 * For a stock item: what is on hand, what the open orders reserve of it, and what is left (short when below zero).
 */
export default async function BackOrdersPage() {
  const back = await getBackOrders();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / <Link href="/sales-orders" className="hover:underline">Sales orders</Link> / Back-orders</>}
        title="Back-orders"
        actions={<Link href="/products" className="btn">Products and services</Link>}
      />
      {back === null ? <Card><p className="text-sm text-muted">Not available in the demo.</p></Card> : (
        <Card className="!p-2" label="Back-orders">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr>
                  <th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Deliver by</th><th scope="col">Line</th>
                  <th scope="col" className="!text-right">Ordered</th><th scope="col" className="!text-right">Delivered</th><th scope="col" className="!text-right">Left</th>
                  <th scope="col" className="!text-right">On hand</th><th scope="col" className="!text-right">Available</th>
                </tr>
              </thead>
              <tbody>
                {back.data.length === 0 && <tr><td colSpan={9} className="text-muted">Nothing is waiting: every confirmed order is delivered.</td></tr>}
                {back.data.map((l) => {
                  const stock = l.item_id ? back.stock[l.item_id] : undefined;
                  const short = stock !== undefined && parse(stock.available) < 0n;
                  return (
                    <tr key={l.line_id}>
                      <td><Link href={`/sales-orders/${encodeURIComponent(l.order_id)}`} className="num font-semibold hover:underline">{l.number ?? "draft"}</Link></td>
                      <td><Link href={`/customers/${encodeURIComponent(l.contact_id)}`} className="hover:underline">{l.customer}</Link></td>
                      <td className="num"><Dt v={l.due_date} /></td>
                      <td>{l.item_name ?? l.description}{l.sku && <span className="num ml-1 text-[12px] text-muted">{l.sku}</span>}</td>
                      <td className="num text-right">{formatAmount(l.ordered)}</td>
                      <td className="num text-right">{formatAmount(l.delivered)}</td>
                      <td className="num text-right font-semibold">{formatAmount(l.remaining)}</td>
                      <td className="num text-right">{stock ? formatAmount(stock.on_hand) : "—"}</td>
                      <td className={`num text-right ${short ? "font-semibold text-bad" : ""}`}>{stock ? <>{formatAmount(stock.available)}{short && <span className="sr-only"> (short)</span>}</> : "—"}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {back.data.length > 0 && (
            <p className="px-3 pb-2 pt-3 text-[12px] text-muted">
              Available is on hand less what every open order reserves of the item; below zero, the orders want more than there is.
              Deliver from an order&apos;s page: invoice what goes out now, and the rest stays here.
            </p>
          )}
        </Card>
      )}
    </>
  );
}

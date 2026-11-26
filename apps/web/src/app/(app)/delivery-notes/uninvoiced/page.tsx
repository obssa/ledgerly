import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getUninvoicedGoods } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Delivered, not invoiced" };

/** Module 2: goods that left on delivery notes and are not yet invoiced (071), for the month-end cut-off. */
export default async function UninvoicedGoodsPage() {
  const rows = (await getUninvoicedGoods())?.data ?? [];
  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / <Link href="/delivery-notes" className="hover:underline">Delivery notes</Link> / Delivered, not invoiced</>}
        title="Delivered, not invoiced"
      />
      <Card className="!p-2" label="Goods delivered not invoiced">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">Everything delivered is invoiced. Nothing is waiting to be billed to a customer.</p>
        ) : (
          <>
            <p className="p-3 text-[13px] text-muted">Their cost of goods sold is already recorded; invoice them before the month closes so the sale is in the same month.</p>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Sales order</th><th scope="col">Customer</th><th scope="col">Line</th><th scope="col">Last delivered</th>
                    <th scope="col" className="!text-right">Delivered</th><th scope="col" className="!text-right">Invoiced</th>
                    <th scope="col" className="!text-right">To invoice</th><th scope="col" className="!text-right">Order price</th></tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.line_id}>
                      <td className="num font-semibold"><Link href={`/sales-orders/${encodeURIComponent(r.order_id)}`} className="hover:underline">{r.number ?? "—"}</Link></td>
                      <td>{r.customer}</td>
                      <td>{r.line_no}. {r.description}</td>
                      <td className="num">{r.last_delivered}</td>
                      <td className="num text-right">{r.delivered}</td>
                      <td className="num text-right">{r.invoiced}</td>
                      <td className="num text-right font-semibold">{r.open} {r.unit ?? ""}</td>
                      <td className="num text-right">{formatAmount(r.unit_price)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </Card>
    </>
  );
}

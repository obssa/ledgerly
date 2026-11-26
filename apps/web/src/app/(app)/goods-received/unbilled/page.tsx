import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getUnbilledGoods } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Received, not billed" };

/** Module 3: goods received not invoiced (069), line by line. The total is the balance of the GRNI account. */
export default async function UnbilledGoodsPage() {
  const unbilled = await getUnbilledGoods();
  const rows = unbilled?.data ?? [];
  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / <Link href="/goods-received" className="hover:underline">Goods received</Link> / Received, not billed</>}
        title="Received, not billed"
      />
      <Card className="!p-2" label="Goods received not invoiced">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">Every delivery received is billed. Nothing is waiting for a supplier&apos;s invoice.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Purchase order</th><th scope="col">Supplier</th><th scope="col">Line</th><th scope="col">Last arrived</th>
                  <th scope="col" className="!text-right">Received</th><th scope="col" className="!text-right">Billed</th>
                  <th scope="col" className="!text-right">Waiting for a bill</th><th scope="col" className="!text-right">Value</th></tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.line_id}>
                    <td className="num font-semibold"><Link href={`/purchase-orders/${encodeURIComponent(r.order_id)}`} className="hover:underline">{r.number ?? "—"}</Link></td>
                    <td>{r.supplier}</td>
                    <td>{r.line_no}. {r.description}</td>
                    <td className="num">{r.last_received}</td>
                    <td className="num text-right">{r.received}</td>
                    <td className="num text-right">{r.billed}</td>
                    <td className="num text-right font-semibold">{r.open} {r.unit ?? ""}</td>
                    <td className="num text-right">{formatAmount(r.value)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr><th scope="row" colSpan={7} className="text-right">Goods received not invoiced</th><td className="num text-right font-bold">{formatAmount(unbilled?.total ?? "0")}</td></tr>
              </tfoot>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

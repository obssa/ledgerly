import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getGoodsReceipts } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Goods received" };

/** Module 3: the goods received notes (069), newest first. Each is recorded from its purchase order. */
export default async function GoodsReceivedPage() {
  const rows = await getGoodsReceipts();
  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / Goods received</>}
        title="Goods received"
        actions={<Link href="/goods-received/unbilled" className="btn">Received, not billed</Link>}
      />
      <Card className="!p-2" label="Goods received notes">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">No goods received yet. When goods arrive, open their purchase order and record what came: it goes into stock at the order&apos;s price.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Number</th><th scope="col">Arrived</th><th scope="col">Supplier</th><th scope="col">Purchase order</th>
                  <th scope="col">Their delivery note</th><th scope="col">Warehouse</th><th scope="col">Status</th><th scope="col" className="!text-right">Value</th></tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td className="num font-semibold"><Link href={`/goods-received/${encodeURIComponent(r.id)}`} className="hover:underline">{r.number}</Link></td>
                    <td className="num">{r.received_on}</td>
                    <td>{r.supplier}</td>
                    <td className="num"><Link href={`/purchase-orders/${encodeURIComponent(r.purchase_order_id)}`} className="hover:underline">{r.order_number ?? "—"}</Link></td>
                    <td className="num">{r.reference ?? "—"}</td>
                    <td>{r.warehouse}</td>
                    <td>{r.status === "void" ? <Pill>Void</Pill> : <Pill tone="good">Received</Pill>}</td>
                    <td className="num text-right">{formatAmount(r.value ?? "0")}</td>
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

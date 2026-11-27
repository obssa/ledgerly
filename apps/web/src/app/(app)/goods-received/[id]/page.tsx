import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getGoodsReceipt } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { VoidGoodsReceipt } from "./VoidGoodsReceipt";

export const metadata = { title: "Goods received note" };

/** One goods received note (069): what arrived, at the order's cost, and its void while its goods are not billed. */
export default async function GoodsReceiptPage({ params }: PageProps<"/goods-received/[id]">) {
  const { id } = await params;
  const r = await getGoodsReceipt(id);
  if (!r) notFound();
  const total = r.lines.reduce((s, l) => s + Number(l.amount), 0);

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / <Link href="/goods-received" className="hover:underline">Goods received</Link> / {r.number}</>}
        title={<>Goods received <span className="num">{r.number}</span> {r.status === "void" ? <Pill>Void</Pill> : <Pill tone="good">Received</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Goods received note">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Supplier</div>
              <Link href={`/suppliers/${encodeURIComponent(r.contact_id)}`} className="font-bold hover:underline">{r.supplier}</Link>
              <div className="mt-1 text-[13px]">Purchase order{" "}
                <Link href={`/purchase-orders/${encodeURIComponent(r.purchase_order_id)}`} className="num font-semibold hover:underline">{r.order_number ?? "—"}</Link></div>
              <div className="text-[13px]">Their delivery note <span className="num font-semibold">{r.reference ?? "—"}</span></div>
            </div>
            <div className="text-right">
              <div><span className="text-muted">Arrived</span> <span className="num"><Dt v={r.received_on} /></span></div>
              <div><span className="text-muted">Into</span> {r.warehouse}</div>
            </div>
          </div>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Order line</th><th scope="col">Item</th><th scope="col" className="!text-right">Quantity</th><th scope="col" className="!text-right">Value at the order&apos;s cost</th></tr>
              </thead>
              <tbody>
                {r.lines.map((l) => (
                  <tr key={l.id}>
                    <td>{l.line_no}. {l.description}</td>
                    <td>{l.item_name}</td>
                    <td className="num text-right">{l.quantity} {l.unit ?? ""}</td>
                    <td className="num text-right">{formatAmount(l.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-4 text-sm"><span className="text-muted">Into stock, as goods received not yet billed:</span> <span className="num font-semibold">{formatAmount(total.toFixed(2))}</span></p>
          {r.note && <p className="mt-2 whitespace-pre-line text-sm text-muted">{r.note}</p>}
          {r.status === "void" && <p className="mt-2 text-[13px] text-muted">Voided{r.void_reason ? `: ${r.void_reason}` : ""}. Its goods went out of stock again.</p>}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {r.can.void && <VoidGoodsReceipt id={r.id} />}
          {r.journal_entry_id && <Link href={`/journal/${encodeURIComponent(r.journal_entry_id)}`} className="btn justify-center">See its journal entry</Link>}
        </aside>
      </div>
    </>
  );
}

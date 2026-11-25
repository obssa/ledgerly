import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";
import { Card, PageTitle } from "@/components/ui";
import { getBinCard, getItem, getWarehouses, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Bin card" };

const KIND: Record<string, string> = { opening: "Opening", purchase: "Received", sale: "Sold", sale_return: "Returned in", purchase_return: "Returned out",
  transfer_in: "Transfer in", transfer_out: "Transfer out", adjustment: "Adjustment", production: "Production", project_issue: "Issued to a project",
  landed_cost: "Landed cost" };
const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/i.test(v) ? v : undefined);

/** The stock card of one item in one warehouse: every move in and out, with the running quantity and value. */
export default async function BinCardPage({ searchParams }: PageProps<"/inventory/bin-card">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "this_fiscal_year_to_date");
  const itemId = uuid(q.item);
  const warehouseId = uuid(q.warehouse);
  if (!itemId || !warehouseId) notFound();
  await connection();
  const { from, to } = period;
  const [item, warehouses, card] = await Promise.all([getItem(itemId), getWarehouses(), getBinCard({ item_id: itemId, warehouse_id: warehouseId, from, to })]);
  if (!item || !card) notFound();
  const warehouse = warehouses.find((w) => w.id === warehouseId);

  return (
    <>
      <PageTitle eyebrow={<><Link href={`/products/${encodeURIComponent(item.id)}`} className="hover:underline">{item.name}</Link> / Bin card</>}
        title={<>Bin card · {warehouse?.name ?? "Warehouse"}</>} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["item", "warehouse"]}>
        <input type="hidden" name="item" value={itemId} /><input type="hidden" name="warehouse" value={warehouseId} />
      </DateRangeFilter>
      <Card className="!p-2" label="Bin card">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Reference</th><th scope="col" className="!text-right">In</th><th scope="col" className="!text-right">Out</th><th scope="col" className="!text-right">Unit cost</th><th scope="col" className="!text-right">Balance</th><th scope="col" className="!text-right">Value</th></tr></thead>
            <tbody>
              <tr><td className="num">{from}</td><td colSpan={5} className="font-semibold">Brought forward</td><td className="num text-right font-semibold">{formatAmount(card.opening.quantity)}</td><td className="num text-right font-semibold">{formatAmount(card.opening.value)}</td></tr>
              {card.moves.map((m, i) => (
                <tr key={i}>
                  <td className="num">{m.moved_on}</td>
                  <td>{KIND[m.kind] ?? m.kind}</td>
                  <td>{m.reference ?? "—"}</td>
                  <td className="num text-right">{m.qty_in === "0.00" ? "" : formatAmount(m.qty_in)}</td>
                  <td className="num text-right">{m.qty_out === "0.00" ? "" : formatAmount(m.qty_out)}</td>
                  <td className="num text-right">{formatAmount(m.unit_cost)}</td>
                  <td className="num text-right">{formatAmount(m.balance_qty)}</td>
                  <td className="num text-right">{formatAmount(m.balance_value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

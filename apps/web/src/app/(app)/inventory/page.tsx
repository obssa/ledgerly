import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getReorder, getStock, getWarehouses } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { NewWarehouse, TransferStock } from "../products/ItemForms";

export const metadata = { title: "Stock on hand" };

/** Module 4: what is on hand in each warehouse, valued at weighted average cost. The total is the inventory account. */
export default async function InventoryPage() {
  const [stock, warehouses, low] = await Promise.all([getStock(), getWarehouses(), getReorder()]);
  const active = warehouses.filter((w) => w.is_active);
  const stockItems = stock ? [...new Map(stock.data.map((r) => [r.item.id, { id: r.item.id, name: r.item.name }])).values()] : [];

  return (
    <>
      <PageTitle eyebrow="Operations" title="Stock on hand"
        actions={<><Link href="/reports/inventory-valuation" className="btn">Valuation</Link><Link href="/products" className="btn">Products and services</Link></>} />
      {low.length > 0 && (
        <Card className="mb-4" label="Running low">
          <h2 className="mb-2 text-[15px] font-bold text-warn">Running low</h2>
          <ul className="text-sm">
            {low.map((r) => (
              <li key={r.item.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                <Link href={`/products/${encodeURIComponent(r.item.id)}`} className="font-semibold hover:underline">{r.item.name}</Link>
                <span className="num">{formatAmount(r.on_hand)} on hand · reorder at {formatAmount(r.item.reorder_point)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {stock === null ? <Card><p className="text-sm text-muted">Not available in the demo.</p></Card> : (
        <>
          <Card className="!p-2" label="Stock on hand">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Item</th><th scope="col">Warehouse</th><th scope="col" className="!text-right">On hand</th><th scope="col" className="!text-right">Average cost</th><th scope="col" className="!text-right">Value</th><th scope="col"><span className="sr-only">Bin card</span></th></tr></thead>
                <tbody>
                  {stock.data.length === 0 && <tr><td colSpan={6} className="text-muted">Nothing on hand.</td></tr>}
                  {stock.data.map((r) => (
                    <tr key={`${r.item.id}-${r.warehouse.id}`}>
                      <td className="font-semibold"><Link href={`/products/${encodeURIComponent(r.item.id)}`} className="hover:underline">{r.item.name}</Link></td>
                      <td>{r.warehouse.name}</td>
                      <td className="num text-right">{formatAmount(r.quantity)} {r.item.unit}</td>
                      <td className="num text-right">{formatAmount(r.average_cost)}</td>
                      <td className="num text-right font-semibold">{formatAmount(r.value)}</td>
                      <td><Link href={`/inventory/bin-card?item=${encodeURIComponent(r.item.id)}&warehouse=${encodeURIComponent(r.warehouse.id)}`} className="text-[13px] font-semibold text-brand hover:underline">Bin card</Link></td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={4}>Total</td><td className="num text-right">{formatAmount(stock.total_value)}</td><td /></tr></tfoot>
              </table>
            </div>
          </Card>
          {stockItems.length > 0 && active.length > 1 && (
            <Card className="mt-4" label="Move stock">
              <h2 className="mb-2 text-[15px] font-bold">Move stock between warehouses</h2>
              <TransferStock items={stockItems} warehouses={active} />
            </Card>
          )}
          <Card className="mt-4 max-w-[620px]" label="Warehouses">
            <h2 className="mb-2 text-[15px] font-bold">Warehouses</h2>
            <ul className="mb-3 text-sm">{warehouses.map((w) => <li key={w.id} className="border-t border-line py-1.5">{w.name}{!w.is_active && <span className="ml-2 text-muted">archived</span>}</li>)}</ul>
            <NewWarehouse />
          </Card>
        </>
      )}
    </>
  );
}

import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getItems, getStock, getWarehouses } from "@/lib/data";
import { CountForm } from "./CountForm";

export const metadata = { title: "Count stock" };

/**
 * Module 4: a stock count of one warehouse. Every stock item is listed with what the books say is on hand; enter what
 * was counted (leave a line empty to leave that item as it is). Differences are adjusted at cost in one entry.
 */
export default async function CountStockPage({ searchParams }: PageProps<"/inventory/count">) {
  const q = await searchParams;
  const [warehouses, stock, items] = await Promise.all([getWarehouses(), getStock(), getItems({})]);
  const active = warehouses.filter((w) => w.is_active);
  const warehouse = active.find((w) => w.id === q.warehouse) ?? active[0];
  if (!warehouse || !stock || items.demo) {
    return (
      <>
        <PageTitle eyebrow={<><Link href="/inventory" className="hover:underline">Inventory</Link> / Count</>} title="Count stock" />
        <p className="text-sm text-muted">{items.demo ? "Not available in the demo." : "Add a warehouse first."}</p>
      </>
    );
  }
  const onHand = new Map(stock.data.filter((r) => r.warehouse.id === warehouse.id).map((r) => [r.item.id, r.quantity]));
  const lines = items.data.filter((i) => i.type === "inventory" && i.is_active)
    .map((i) => ({ id: i.id, name: i.name, sku: i.sku, unit: i.unit, books: onHand.get(i.id) ?? "0.00" }));

  return (
    <>
      <PageTitle eyebrow={<><Link href="/inventory" className="hover:underline">Inventory</Link> / Count</>} title={`Count stock: ${warehouse.name}`} />
      {active.length > 1 && (
        <nav className="mb-4 flex flex-wrap gap-2" aria-label="Warehouse">
          {active.map((w) => <Link key={w.id} href={`/inventory/count?warehouse=${encodeURIComponent(w.id)}`} aria-current={w.id === warehouse.id ? "page" : undefined}
            className={`btn ${w.id === warehouse.id ? "btn-primary" : ""}`}>{w.name}</Link>)}
        </nav>
      )}
      <Card className="!p-2" label="Count sheet">
        {lines.length === 0 ? <p className="p-3 text-sm text-muted">No stock items to count.</p> : <CountForm warehouseId={warehouse.id} lines={lines} />}
      </Card>
    </>
  );
}

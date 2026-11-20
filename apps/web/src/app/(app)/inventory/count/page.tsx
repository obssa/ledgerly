import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getBins, getItems, getStock, getWarehouses } from "@/lib/data";
import { CountForm } from "./CountForm";

export const metadata = { title: "Count stock" };

/**
 * Module 4: a stock count of one warehouse. Every stock item is listed with what the books say is on hand; enter what
 * was counted (leave a line empty to leave that item as it is). Differences are adjusted at cost in one entry. 098: a
 * warehouse with bins is counted one bin at a time, or whole with each item's bin shown, in bin order.
 */
export default async function CountStockPage({ searchParams }: PageProps<"/inventory/count">) {
  const q = await searchParams;
  const [warehouses, stock, items, bins] = await Promise.all([getWarehouses(), getStock(), getItems({}), getBins()]);
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
  const here = (bins?.data ?? []).filter((b) => b.warehouse_id === warehouse.id && b.is_active);
  const bin = here.find((b) => b.id === q.bin);
  const binOf = new Map(here.flatMap((b) => b.items.map((i) => [i.id, b.code] as const)));
  const kept = bin ? new Set(bin.items.map((i) => i.id)) : null;
  const lines = items.data.filter((i) => i.type === "inventory" && i.is_active && (!kept || kept.has(i.id)))
    .map((i) => ({ id: i.id, name: i.name, sku: i.sku, unit: i.unit, books: onHand.get(i.id) ?? "0.00", bin: binOf.get(i.id) ?? null }))
    .sort((a, b) => (a.bin ?? "\uffff").localeCompare(b.bin ?? "\uffff") || a.name.localeCompare(b.name));
  const href = (w: string, b?: string) => `/inventory/count?warehouse=${encodeURIComponent(w)}${b ? `&bin=${encodeURIComponent(b)}` : ""}`;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/inventory" className="hover:underline">Inventory</Link> / Count</>} title={`Count stock: ${warehouse.name}${bin ? `, bin ${bin.code}` : ""}`}
        actions={<Link href="/inventory/bins" className="btn">Bins</Link>} />
      {active.length > 1 && (
        <nav className="mb-4 flex flex-wrap gap-2" aria-label="Warehouse">
          {active.map((w) => <Link key={w.id} href={href(w.id)} aria-current={w.id === warehouse.id ? "page" : undefined}
            className={`btn ${w.id === warehouse.id ? "btn-primary" : ""}`}>{w.name}</Link>)}
        </nav>
      )}
      {here.length > 0 && (
        <nav className="mb-4 flex flex-wrap gap-2" aria-label="Bin">
          <Link href={href(warehouse.id)} aria-current={!bin ? "page" : undefined} className={`btn ${!bin ? "btn-primary" : ""}`}>Whole warehouse</Link>
          {here.map((b) => <Link key={b.id} href={href(warehouse.id, b.id)} aria-current={b.id === bin?.id ? "page" : undefined}
            className={`btn num ${b.id === bin?.id ? "btn-primary" : ""}`}>{b.code}</Link>)}
        </nav>
      )}
      <Card className="!p-2" label="Count sheet">
        {lines.length === 0 ? <p className="p-3 text-sm text-muted">{bin ? `Nothing is kept in bin ${bin.code} yet: give it its items on the bins page.` : "No stock items to count."}</p>
          : <CountForm key={bin?.id ?? warehouse.id} warehouseId={warehouse.id} binId={bin?.id} showBins={!bin && here.length > 0} lines={lines} />}
      </Card>
    </>
  );
}

import { Card, PageTitle, Segmented } from "@/components/ui";
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
        <PageTitle title="Count stock" />
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
      <PageTitle title={`Count stock: ${warehouse.name}${bin ? `, bin ${bin.code}` : ""}`} />
      {active.length > 1 && (
        <Segmented label="Warehouse" items={active.map((w) => ({ key: w.id, href: href(w.id), label: w.name, active: w.id === warehouse.id }))} />
      )}
      {here.length > 0 && (
        <Segmented label="Bin" size="sm" items={[
          { key: "all", href: href(warehouse.id), label: "Whole warehouse", active: !bin },
          ...here.map((b) => ({ key: b.id, href: href(warehouse.id, b.id), label: <span className="num">{b.code}</span>, active: b.id === bin?.id })),
        ]} />
      )}
      <Card className="!p-2" label="Count sheet">
        {lines.length === 0 ? <p className="p-3 text-sm text-muted">{bin ? `Nothing is kept in bin ${bin.code} yet: give it its products on the bins page.` : "No stock products to count."}</p>
          : <CountForm key={bin?.id ?? warehouse.id} warehouseId={warehouse.id} binId={bin?.id} showBins={!bin && here.length > 0} lines={lines} />}
      </Card>
    </>
  );
}

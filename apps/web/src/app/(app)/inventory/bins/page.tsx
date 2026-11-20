import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getBins, getItems, getWarehouses } from "@/lib/data";
import { AddBin, BinRow } from "./BinForms";

export const metadata = { title: "Bins" };

/**
 * Module 4 (098): the bins inside each warehouse and the stock items kept in each, one bin per item per warehouse. A
 * count can then be taken one bin at a time. The quantity on hand stays the warehouse's.
 */
export default async function BinsPage() {
  const [bins, warehouses, items] = await Promise.all([getBins(), getWarehouses(), getItems({})]);
  const title = <PageTitle eyebrow={<><Link href="/inventory" className="hover:underline">Inventory</Link> / Bins</>} title="Bins" />;
  if (!bins || items.demo) return <>{title}<p className="text-[14px] text-muted">Not available in the demo.</p></>;
  const active = warehouses.filter((w) => w.is_active);
  const stock = items.data.filter((i) => i.type === "inventory" && i.is_active).map((i) => ({ id: i.id, name: i.name, sku: i.sku }));
  return (
    <>
      {title}
      <p className="mb-4 max-w-[760px] text-[13px] text-muted">
        A bin is a place inside a warehouse: a shelf, a rack, the cold room. Give each stock item the bin it is kept in, and count stock one bin at a time.
        How much is on hand stays the warehouse&rsquo;s; a bin says where to look. A bin is archived once nothing is kept in it.
      </p>
      {bins.can.manage && active.length > 0 && <Card className="mb-4 max-w-[760px]" label="New bin"><AddBin warehouses={active.map((w) => ({ id: w.id, name: w.name }))} /></Card>}
      {active.length === 0 ? <p className="text-sm text-muted">Add a warehouse first.</p> : active.map((w) => {
        const mine = bins.data.filter((b) => b.warehouse_id === w.id);
        return (
          <Card key={w.id} className="mb-4 max-w-[760px]" label={w.name}>
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="text-[15px] font-bold">{w.name}</h2>
              {mine.some((b) => b.is_active) && <Link href={`/inventory/count?warehouse=${encodeURIComponent(w.id)}`} className="text-[13px] font-semibold text-brand hover:underline">Count by bin</Link>}
            </div>
            {mine.length === 0 ? <p className="text-sm text-muted">No bins in {w.name} yet.</p> : (
              <ul>{mine.map((b) => <BinRow key={b.id} bin={b} stock={stock} manage={bins.can.manage} />)}</ul>
            )}
          </Card>
        );
      })}
    </>
  );
}

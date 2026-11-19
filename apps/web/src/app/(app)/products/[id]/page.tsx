import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getItem, getItems, getWarehouses } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { AdjustStock, ItemForm } from "../ItemForms";
import { ItemUnits } from "./ItemUnits";
import { BundleParts } from "./BundleParts";

export const metadata = { title: "Item" };

/** One item: what is on hand in each warehouse (with its bin card), its details, and stock adjustments. */
export default async function ItemPage({ params }: PageProps<"/products/[id]">) {
  const { id } = await params;
  const [item, warehouses] = await Promise.all([getItem(id), getWarehouses()]);
  if (!item) notFound();
  const stock = item.type === "inventory";
  const choices = item.type === "bundle" ? (await getItems({})).data.filter((i) => i.type !== "bundle" && i.id !== item.id) : [];

  return (
    <>
      <PageTitle eyebrow={<><Link href="/products" className="hover:underline">Products and services</Link> / Item</>}
        title={<>{item.name} {!item.is_active && <Pill>Archived</Pill>}</>} />
      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        {stock && (
          <Card className="min-w-0 flex-[1_1_300px]" label="On hand">
            <h2 className="text-[15px] font-bold">On hand</h2>
            {(item.balances ?? []).length === 0 ? <p className="mt-2 text-sm text-muted">None yet. Record opening stock below.</p> : (
              <ul className="mt-2 text-sm">
                {item.balances!.map((b) => (
                  <li key={b.warehouse.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                    <Link href={`/inventory/bin-card?item=${encodeURIComponent(item.id)}&warehouse=${encodeURIComponent(b.warehouse.id)}`} className="hover:underline">{b.warehouse.name}</Link>
                    <span className="num">{formatAmount(b.quantity)} {item.unit} · {formatAmount(b.value)}</span>
                  </li>
                ))}
              </ul>
            )}
            {item.can?.adjust && warehouses.length > 0 && (
              <div className="mt-5 border-t border-line pt-4">
                <h3 className="mb-2 text-[14px] font-bold">Adjust stock</h3>
                <AdjustStock item={item} warehouses={warehouses.filter((w) => w.is_active)} />
              </div>
            )}
          </Card>
        )}
        <Card className="min-w-0 flex-[2_1_520px]" label="Item details">
          <ItemForm item={item} readOnly={!item.can?.edit} />
        </Card>
        {item.type === "bundle" && (
          <Card className="min-w-0 flex-[1_1_360px]" label="Parts">
            <BundleParts item={item} choices={choices.map((i) => ({ id: i.id, name: i.name, unit: i.unit, type: i.type }))} />
          </Card>
        )}
        {item.type !== "service" && (
          <Card className="min-w-0 flex-[1_1_300px]" label="Units">
            <ItemUnits item={item} />
          </Card>
        )}
      </div>
    </>
  );
}

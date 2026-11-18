import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getItems } from "@/lib/data";
import { parse } from "@/lib/decimal";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Products and services" };

const TYPE: Record<string, string> = { inventory: "Stock", non_inventory: "Non-stock", service: "Service", bundle: "Bundle" };

/** Module 4: what the company sells and buys, and what is on hand of each stock item. */
export default async function ProductsPage({ searchParams }: PageProps<"/products">) {
  const { q, archived } = await searchParams;
  const search = typeof q === "string" ? q.slice(0, 100) : "";
  const { data, can, demo } = await getItems({ search, archived: archived === "1" });

  return (
    <>
      <PageTitle eyebrow="Money in" title="Products and services"
        actions={<><Link href="/sales-orders/back-orders" className="btn">Back-orders</Link><Link href="/inventory" className="btn">Stock on hand</Link>{can.edit && !demo && <Link href="/products/new" className="btn btn-primary">+ New item</Link>}</>} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5" role="search">
        <div className="min-w-[240px] flex-1"><label className="field-label" htmlFor="q">Search by name or SKU</label><input className="field" id="q" name="q" defaultValue={search} /></div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="archived" value="1" defaultChecked={archived === "1"} className="size-4" /> Include archived</label>
        <button type="submit" className="btn">Search</button>
      </form>
      <Card className="!p-2" label="Items">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Name</th><th scope="col">SKU</th><th scope="col">Type</th><th scope="col" className="!text-right">Sale price</th><th scope="col" className="!text-right">On hand</th><th scope="col" className="!text-right">Reserved</th><th scope="col" className="!text-right">Available</th><th scope="col" className="!text-right">On order</th><th scope="col" className="!text-right">Value</th></tr></thead>
            <tbody>
              {data.length === 0 && <tr><td colSpan={9} className="text-muted">{search ? "No item matches." : "No items yet."}</td></tr>}
              {data.map((i) => {
                const low = i.type === "inventory" && i.reorder_point !== null && parse(i.on_hand ?? "0") <= parse(i.reorder_point);
                return (
                  <tr key={i.id}>
                    <td className="font-semibold"><Link href={`/products/${encodeURIComponent(i.id)}`} className="hover:underline">{i.name}</Link>{!i.is_active && <span className="ml-2 text-[12px] font-normal text-muted">archived</span>}</td>
                    <td className="num">{i.sku ?? "—"}</td>
                    <td>{TYPE[i.type] ?? i.type}</td>
                    <td className="num text-right">{i.sale_price ? formatAmount(i.sale_price) : "—"}</td>
                    <td className={`num text-right ${low ? "font-semibold text-warn" : ""}`}>{i.type === "inventory" ? `${formatAmount(i.on_hand ?? "0")} ${i.unit}` : "—"}</td>
                    <td className="num text-right">{i.type === "inventory" ? formatAmount(i.reserved ?? "0") : "—"}</td>
                    <td className={`num text-right ${i.available && parse(i.available) < 0n ? "font-semibold text-bad" : ""}`}>{i.type === "inventory" && i.available ? formatAmount(i.available) : "—"}</td>
                    <td className="num text-right">{i.type === "inventory" ? formatAmount(i.on_order ?? "0") : "—"}</td>
                    <td className="num text-right">{i.type === "inventory" ? formatAmount(i.value ?? "0") : "—"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

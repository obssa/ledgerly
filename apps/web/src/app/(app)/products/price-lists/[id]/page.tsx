import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getItems, getPriceList } from "@/lib/data";
import { PriceListDetails, PricesEditor } from "../PriceListForms";

export const metadata = { title: "Price list" };

/** One price list (080): its name and currency, a price per item, and the customers on it. */
export default async function PriceListPage({ params }: PageProps<"/products/price-lists/[id]">) {
  const { id } = await params;
  const [list, items] = await Promise.all([getPriceList(id), getItems({})]);
  if (!list) notFound();
  const readOnly = !list.can.manage;
  return (
    <>
      <PageTitle title={<>{list.name} {!list.is_active && <Pill>Archived</Pill>}</>}
        actions={<Link href="/products/price-lists" className="btn">All price lists</Link>} />
      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Prices">
          <h2 className="mb-1 text-[17px] font-bold">Prices</h2>
          <p className="mb-3 text-[13px] text-muted">Suggested on invoices, quotes and orders in {list.currency} for the customers on this list. An item without a price here keeps its own.</p>
          <PricesEditor list={list} items={items.data.filter((i) => i.is_active).map((i) => ({ id: i.id, name: i.name, sku: i.sku, sale_price: i.sale_price }))} readOnly={readOnly} />
        </Card>
        <div className="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
          <Card label="Price list">
            <PriceListDetails list={list} readOnly={readOnly} />
          </Card>
          <Card label="Customers on this list">
            <h2 className="text-[17px] font-bold">Customers</h2>
            {list.customers.length === 0 ? (
              <p className="mt-1.5 text-[13px] text-muted">None yet. Put a customer on this list from their page.</p>
            ) : (
              <ul className="mt-2 flex flex-col gap-1 text-[13px]">
                {list.customers.map((c) => <li key={c.id}><Link href={`/customers/${encodeURIComponent(c.id)}`} className="underline">{c.name}</Link></li>)}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}

import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCompanySettings, getPriceLists } from "@/lib/data";
import { NewPriceList } from "./PriceListForms";

export const metadata = { title: "Price lists" };

/** Module 2, customer groups and price lists (080): each list is a group of customers who pay its prices. */
export default async function PriceListsPage() {
  const [lists, settings] = await Promise.all([getPriceLists(), getCompanySettings()]);
  return (
    <>
      <PageTitle title="Price lists" />
      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label="Price lists">
          <p className="mb-3 text-sm text-muted">
            A price list is a group of customers who pay its prices. Put a customer on a list on their page; on an invoice, quote or order in the
            list&apos;s currency, choosing an item suggests the list&apos;s price for them. Any price can still be typed.
          </p>
          {lists.data.length === 0 ? (
            <p className="text-sm text-muted">No price lists yet.</p>
          ) : (
            <table className="w-full text-sm">
              <thead><tr className="text-left text-[12px] text-muted"><th className="py-2 pr-3">Name</th><th className="py-2 pr-3">Currency</th><th className="py-2 pr-3 text-right">Products and services</th><th className="py-2 text-right">Customers</th></tr></thead>
              <tbody>
                {lists.data.map((l) => (
                  <tr key={l.id} className="border-t border-line">
                    <td className="py-2 pr-3"><Link href={`/products/price-lists/${encodeURIComponent(l.id)}`} className="font-semibold underline">{l.name}</Link> {!l.is_active && <Pill>Archived</Pill>}</td>
                    <td className="py-2 pr-3">{l.currency}</td>
                    <td className="num py-2 pr-3 text-right">{l.items}</td>
                    <td className="num py-2 text-right">{l.customers}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        {lists.can.manage && (
          <Card className="min-w-0 flex-[1_1_280px]" label="New price list">
            <h2 className="mb-3 text-[17px] font-bold">New price list</h2>
            <NewPriceList baseCurrency={settings.base_currency} />
          </Card>
        )}
      </div>
    </>
  );
}

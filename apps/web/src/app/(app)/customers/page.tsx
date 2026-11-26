import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getCustomers } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Customers" };

/** Module 2: who the company sells to, and what each still owes on posted invoices (base currency). */
export default async function CustomersPage({ searchParams }: PageProps<"/customers">) {
  const { q, archived } = await searchParams;
  const search = typeof q === "string" ? q.slice(0, 100) : "";
  const { data, can, demo } = await getCustomers({ search, archived: archived === "1" });

  return (
    <>
      <PageTitle
        eyebrow="Money in"
        title="Customers"
        actions={can.create && !demo ? <Link href="/customers/new" className="btn btn-primary">+ New customer</Link> : undefined}
      />
      <form className="mb-4 flex flex-wrap items-end gap-2.5" role="search">
        <div className="min-w-[240px] flex-1">
          <label className="field-label" htmlFor="q">Search by name or TIN</label>
          <input className="field" id="q" name="q" defaultValue={search} />
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="archived" value="1" defaultChecked={archived === "1"} className="size-4" /> Include archived</label>
        <button type="submit" className="btn">Search</button>
      </form>
      <Card className="!p-2" label="Customers">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Name</th><th scope="col">TIN</th><th scope="col">Terms</th>
                <th scope="col" className="!text-right">Credit limit</th><th scope="col" className="!text-right">Owes</th>
              </tr>
            </thead>
            <tbody>
              {data.length === 0 && (
                <tr><td colSpan={5} className="text-muted">{search ? "No customer matches." : "No customers yet."}</td></tr>
              )}
              {data.map((c) => (
                <tr key={c.id}>
                  <td className="font-semibold">
                    <Link href={`/customers/${encodeURIComponent(c.id)}`} className="hover:underline">{c.name}</Link>
                    {!c.is_active && <span className="ml-2 text-[12px] font-normal text-muted">archived</span>}
                  </td>
                  <td className="num">{c.tax_id ?? "—"}</td>
                  <td>{c.payment_terms_days === 0 ? "On receipt" : `${c.payment_terms_days} days`}</td>
                  <td className="num text-right">{c.credit_limit ? formatAmount(c.credit_limit) : "—"}</td>
                  <td className="num text-right font-semibold">{formatAmount(c.open_balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

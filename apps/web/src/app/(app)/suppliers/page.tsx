import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { termsLabel } from "@/components/TermsSelect";
import { getPaymentTerms, getSuppliers } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Suppliers" };

/** Module 3: who the company buys from, and what it still owes each on posted bills (base currency). */
export default async function SuppliersPage({ searchParams }: PageProps<"/suppliers">) {
  const { q, archived } = await searchParams;
  const search = typeof q === "string" ? q.slice(0, 100) : "";
  const [{ data, can, demo }, terms] = await Promise.all([getSuppliers({ search, archived: archived === "1" }), getPaymentTerms(true)]);

  return (
    <>
      <PageTitle
       
        title="Suppliers"
        actions={can.create && !demo ? <Link href="/suppliers/new" className="btn btn-primary">+ New supplier</Link> : undefined}
      />
      <form className="mb-4 flex flex-wrap items-end gap-2.5" role="search">
        <div className="min-w-[240px] flex-1">
          <label className="field-label" htmlFor="q">Search by name or TIN</label>
          <input className="field" id="q" name="q" defaultValue={search} />
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="archived" value="1" defaultChecked={archived === "1"} className="size-4" /> Include archived</label>
        <button type="submit" className="btn">Search</button>
      </form>
      <Card className="!p-2" label="Suppliers">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Name</th><th scope="col">TIN</th><th scope="col">Terms</th><th scope="col" className="!text-right">We owe</th></tr>
            </thead>
            <tbody>
              {data.length === 0 && <tr><td colSpan={4} className="text-muted">{search ? "No supplier matches." : "No suppliers yet."}</td></tr>}
              {data.map((s) => (
                <tr key={s.id}>
                  <td className="font-semibold">
                    <Link href={`/suppliers/${encodeURIComponent(s.id)}`} className="hover:underline">{s.name}</Link>
                    {!s.is_active && <span className="ml-2 text-[12px] font-normal text-muted">archived</span>}
                  </td>
                  <td className="num">{s.tax_id ?? "—"}</td>
                  <td>{termsLabel(terms, s.payment_terms_id, s.payment_terms_days)}</td>
                  <td className="num text-right font-semibold">{formatAmount(s.open_balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

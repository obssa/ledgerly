import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getSalesReceipts } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Cash sales" };

/** Module 2: sales paid on the spot (070), newest first. */
export default async function SalesReceiptsPage() {
  const receipts = await getSalesReceipts();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Cash sales</>} title="Cash sales"
        actions={<Link href="/sales-receipts/new" className="btn btn-primary">+ Record a cash sale</Link>} />
      <Card className="!p-2" label="Cash sales">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Number</th><th scope="col">Date</th><th scope="col">Customer</th><th scope="col">Slip</th><th scope="col">Received into</th>
                <th scope="col" className="!text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {receipts.length === 0 && <tr><td colSpan={6} className="text-muted">No cash sales yet. A sale paid on the spot is recorded here, without an invoice to collect.</td></tr>}
              {receipts.map((r) => (
                <tr key={r.id}>
                  <td className="num font-semibold">
                    <Link href={`/sales-receipts/${encodeURIComponent(r.id)}`} className="hover:underline">{r.number ?? "—"}</Link>
                    {r.status === "void" && <> <Pill>Void</Pill></>}
                  </td>
                  <td className="num">{r.issue_date}</td>
                  <td>{r.contact.name}</td>
                  <td className="num">{r.reference ?? "—"}</td>
                  <td>{r.paid_from ? `${r.paid_from.code} ${r.paid_from.name}` : "—"}</td>
                  <td className="num text-right font-semibold">{formatMoney(r.total, r.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

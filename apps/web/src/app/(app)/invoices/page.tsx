import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { Invoice } from "@/lib/api";
import { getInvoices } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Invoices" };

/** Tax-authority status. Only countries with an e-invoicing connector have submissions. */
function einvoiceBadge(inv: Invoice) {
  const s = inv.einvoice_submissions?.[0];
  if (inv.status === "draft") return <Pill>Draft</Pill>;
  if (inv.status === "awaiting_approval") return <Pill tone="warn">Awaiting approval</Pill>;
  if (inv.status === "void") return <Pill>Void</Pill>;
  if (!s) return <Pill>Not required</Pill>;
  if (s.status === "rejected") return <Pill tone="bad">Rejected · {s.last_error}</Pill>;
  if (s.status === "cleared") return <Pill tone="good">Cleared</Pill>;
  return <Pill tone="warn">Queued</Pill>;
}

export default async function InvoicesPage() {
  const invoices = await getInvoices();

  return (
    <>
      <PageTitle
        eyebrow="Money in"
        title="Sales and invoices"
        actions={<><Link href="/quotes" className="btn">Quotes</Link><Link href="/sales-receipts" className="btn">Cash sales</Link><Link href="/invoices/recurring" className="btn">Recurring</Link><Link href="/credit-notes" className="btn">Credit notes</Link><Link href="/invoices/new" className="btn btn-primary">+ New invoice</Link></>}
      />
      <Card className="!p-2" label="Invoices">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Number</th><th scope="col">Customer</th><th scope="col">Issued</th><th scope="col">Due</th>
                <th scope="col">E-invoice</th><th scope="col" className="!text-right">Total</th><th scope="col" className="!text-right">Due now</th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => (
                <tr key={inv.id}>
                  <td className="num font-semibold">
                    <Link href={`/invoices/${encodeURIComponent(inv.id)}`} className="hover:underline">{inv.number ?? <span className="text-muted">Draft</span>}</Link>
                  </td>
                  <td>{inv.contact.name}</td>
                  <td>{inv.issue_date}</td>
                  <td>{inv.due_date ?? "—"}</td>
                  <td>{einvoiceBadge(inv)}</td>
                  <td className="num text-right">{formatMoney(inv.total, inv.currency)}</td>
                  <td className="num text-right font-semibold">{formatMoney(inv.amount_due, inv.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

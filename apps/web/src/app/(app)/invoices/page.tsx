import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { Invoice } from "@/lib/api";
import { getInvoices, getReportPeriod } from "@/lib/data";
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

export default async function InvoicesPage({ searchParams }: PageProps<"/invoices">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const invoices = await getInvoices(listQuery(q, period.picked));

  return (
    <>
      <PageTitle
        eyebrow="Money in"
        title="Sales and invoices"
        actions={<Link href="/invoices/new" className="btn btn-primary">+ New invoice</Link>}
      />
      <ListPeriod picked={period.picked} />
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
      <ListPager path="/invoices" picked={period.picked} next={invoices.next} prev={invoices.prev} />
    </>
  );
}

import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { connection } from "next/server";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { Invoice } from "@/lib/api";
import { getCompanyToday, getInvoices, getReportPeriod } from "@/lib/data";
import { paymentStatus } from "@/lib/docStatus";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Invoices" };

/** Tax-authority status. Only countries with an e-invoicing connector have submissions. */
function einvoiceBadge(inv: Invoice) {
  const s = inv.einvoice_submissions?.[0];
  if (!s) return <span className="text-muted">—</span>;
  if (s.status === "rejected") return <Pill tone="bad">Rejected · {s.last_error}</Pill>;
  if (s.status === "cleared") return <Pill tone="good">Cleared</Pill>;
  return <Pill tone="warn">Queued</Pill>;
}

export default async function InvoicesPage({ searchParams }: PageProps<"/invoices">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const invoices = await getInvoices(listQuery(q, period.picked));
  await connection();
  const today = await getCompanyToday();
  // The e-invoice column only where the country clears invoices with the tax authority.
  const einvoicing = invoices.some((inv) => (inv.einvoice_submissions?.length ?? 0) > 0);

  return (
    <>
      <PageTitle
       
        title="Sales and invoices"
        actions={<Link href="/invoices/new" className="btn btn-primary">+ New invoice</Link>}
      />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Invoices">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Date</th><th scope="col">No.</th><th scope="col">Customer</th><th scope="col">Due date</th>
                <th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Open balance</th><th scope="col">Status</th>
                {einvoicing && <th scope="col">E-invoice</th>}
              </tr>
            </thead>
            <tbody>
              {invoices.length === 0 && <tr><td colSpan={einvoicing ? 8 : 7} className="text-muted">No invoices in this period.</td></tr>}
              {invoices.map((inv) => {
                const s = paymentStatus(inv, today);
                return (
                  <tr key={inv.id}>
                    <td className="num"><Dt v={inv.issue_date} /></td>
                    <td className="num font-semibold">
                      <Link href={`/invoices/${encodeURIComponent(inv.id)}`} className="hover:underline">{inv.number ?? <span className="text-muted">Draft</span>}</Link>
                    </td>
                    <td>{inv.contact.name}</td>
                    <td className="num"><Dt v={inv.due_date} /></td>
                    <td className="num text-right">{formatMoney(inv.total, inv.currency)}</td>
                    <td className="num text-right font-semibold">{formatMoney(inv.status === "void" ? "0.00" : inv.amount_due, inv.currency)}</td>
                    <td><Pill tone={s.tone}>{s.label}</Pill></td>
                    {einvoicing && <td>{einvoiceBadge(inv)}</td>}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/invoices" picked={period.picked} next={invoices.next} prev={invoices.prev} />
    </>
  );
}

import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { connection } from "next/server";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBills, getCompanyToday, getReportPeriod } from "@/lib/data";
import { paymentStatus } from "@/lib/docStatus";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Bills" };

/** Module 3: what suppliers have billed, newest first, and what is still to pay. */
export default async function BillsPage({ searchParams }: PageProps<"/bills">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const bills = await getBills(listQuery(q, period.picked));
  await connection();
  const today = await getCompanyToday();

  return (
    <>
      <PageTitle title="Bills and purchases"
        actions={<Link href="/bills/new" className="btn btn-primary">+ New bill</Link>} />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Bills">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Date</th><th scope="col">No.</th><th scope="col">Supplier</th><th scope="col">Supplier invoice no.</th><th scope="col">Due date</th>
                <th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Open balance</th><th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {bills.length === 0 && <tr><td colSpan={8} className="text-muted">No bills in this period.</td></tr>}
              {bills.map((b) => {
                const s = paymentStatus(b, today);
                return (
                  <tr key={b.id}>
                    <td className="num"><Dt v={b.issue_date} /></td>
                    <td className="num font-semibold">
                      <Link href={`/bills/${encodeURIComponent(b.id)}`} className="hover:underline">{b.number ?? <span className="text-muted">Draft</span>}</Link>
                    </td>
                    <td>{b.contact.name}</td>
                    <td className="num">{b.reference ?? "—"}</td>
                    <td className="num"><Dt v={b.due_date} /></td>
                    <td className="num text-right">{formatMoney(b.total, b.currency)}</td>
                    <td className="num text-right font-semibold">{formatMoney(b.status === "void" ? "0.00" : b.amount_due, b.currency)}</td>
                    <td><Pill tone={s.tone}>{s.label}</Pill></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/bills" picked={period.picked} next={bills.next} prev={bills.prev} />
    </>
  );
}

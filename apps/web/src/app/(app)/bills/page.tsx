import Link from "@/components/Link";
import { connection } from "next/server";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { Bill } from "@/lib/api";
import { getBills, getCompanyToday } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Bills" };

function status(b: Bill, today: string) {
  if (b.status === "draft") return <Pill>Draft</Pill>;
  if (b.status === "awaiting_approval") return <Pill tone="warn">Awaiting approval</Pill>;
  if (b.status === "void") return <Pill>Void</Pill>;
  if (b.status === "paid") return <Pill tone="good">Paid</Pill>;
  if (b.due_date !== null && b.due_date < today) return <Pill tone="bad">Overdue</Pill>;
  return <Pill tone="brand">{b.status === "part_paid" ? "Part paid" : "To pay"}</Pill>;
}

/** Module 3: what suppliers have billed, newest first, and what is still to pay. */
export default async function BillsPage() {
  const bills = await getBills();
  await connection();
  const today = await getCompanyToday();

  return (
    <>
      <PageTitle eyebrow="Money out" title="Bills and purchases"
        actions={<Link href="/bills/new" className="btn btn-primary">+ New bill</Link>} />
      <Card className="!p-2" label="Bills">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Number</th><th scope="col">Supplier</th><th scope="col">Their reference</th><th scope="col">Issued</th><th scope="col">Due</th>
                <th scope="col">Status</th><th scope="col" className="!text-right">Total</th><th scope="col" className="!text-right">To pay</th>
              </tr>
            </thead>
            <tbody>
              {bills.length === 0 && <tr><td colSpan={8} className="text-muted">No bills yet.</td></tr>}
              {bills.map((b) => (
                <tr key={b.id}>
                  <td className="num font-semibold">
                    <Link href={`/bills/${encodeURIComponent(b.id)}`} className="hover:underline">{b.number ?? <span className="text-muted">Draft</span>}</Link>
                  </td>
                  <td>{b.contact.name}</td>
                  <td className="num">{b.reference ?? "—"}</td>
                  <td>{b.issue_date}</td>
                  <td>{b.due_date ?? "—"}</td>
                  <td>{status(b, today)}</td>
                  <td className="num text-right">{formatMoney(b.total, b.currency)}</td>
                  <td className="num text-right font-semibold">{formatMoney(b.amount_due, b.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

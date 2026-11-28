import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getExpenses, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Expenses" };

/** Module 3: what was paid on the spot (fuel, utilities, small purchases), newest first. */
export default async function ExpensesPage({ searchParams }: PageProps<"/expenses">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const expenses = await getExpenses(listQuery(q, period.picked));

  return (
    <>
      <PageTitle title="Expenses" actions={<Link href="/expenses/new" className="btn btn-primary">+ Record an expense</Link>} />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Expenses">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Date</th><th scope="col">No.</th><th scope="col">Payee</th><th scope="col">Receipt no.</th><th scope="col">Payment account</th>
                <th scope="col" className="!text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {expenses.length === 0 && <tr><td colSpan={6} className="text-muted">No expenses yet.</td></tr>}
              {expenses.map((e) => (
                <tr key={e.id}>
                  <td className="num"><Dt v={e.issue_date} /></td>
                  <td className="num font-semibold">
                    <Link href={`/expenses/${encodeURIComponent(e.id)}`} className="hover:underline">{e.number}</Link>
                    {e.status === "void" && <> <Pill>Void</Pill></>}
                  </td>
                  <td>{e.contact.name}</td>
                  <td className="num">{e.reference ?? "—"}</td>
                  <td>{e.paid_from ? `${e.paid_from.code} ${e.paid_from.name}` : "—"}</td>
                  <td className="num text-right font-semibold">{formatMoney(e.total, e.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/expenses" picked={period.picked} next={expenses.next} prev={expenses.prev} />
    </>
  );
}

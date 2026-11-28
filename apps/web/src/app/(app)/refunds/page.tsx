import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getRefunds, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Refunds" };

/** Credit given back: to customers (money out) and from suppliers (money in), newest first. */
export default async function RefundsPage({ searchParams }: PageProps<"/refunds">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const refunds = await getRefunds(listQuery(q, period.picked));
  return (
    <>
      <PageTitle title="Refunds" />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Refunds">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Applied to</th><th scope="col">Reference no.</th><th scope="col" className="!text-right">Amount</th></tr></thead>
            <tbody>
              {refunds.length === 0 && <tr><td colSpan={6} className="text-muted">No refunds yet. Refund a credit note, a supplier credit or an overpayment from its page.</td></tr>}
              {refunds.map((r) => (
                <tr key={r.id}>
                  <td className="num"><Link href={`/refunds/${encodeURIComponent(r.id)}`} className="hover:underline"><Dt v={r.payment_date} /></Link></td>
                  <td>{r.contact?.name}{r.status === "reversed" && <> <Pill tone="bad">Reversed</Pill></>}</td>
                  <td>{r.direction === "out" ? "Customer refund" : "Supplier refund"}</td>
                  <td className="num">{r.allocations?.[0]?.document?.number ?? "Unapplied payment"}</td>
                  <td className="num">{r.reference ?? "—"}</td>
                  <td className="num text-right font-semibold">{formatMoney(r.amount, r.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/refunds" picked={period.picked} next={refunds.next} prev={refunds.prev} />
    </>
  );
}

import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { appliedTo, methodLabel } from "@/lib/docStatus";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getPaymentsReceived, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Payments received" };

export default async function PaymentsPage({ searchParams }: PageProps<"/payments">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const payments = await getPaymentsReceived(listQuery(q, period.picked));

  return (
    <>
      <PageTitle title="Payments received" actions={<Link href="/payments/new" className="btn btn-primary">+ Receive a payment</Link>} />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Payments received">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Date</th><th scope="col">Customer</th><th scope="col">Payment method</th><th scope="col">Reference no.</th><th scope="col">Applied to</th><th scope="col" className="!text-right">Amount</th></tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={6} className="text-muted">No payments yet.</td></tr>}
              {payments.map((p) => (
                <tr key={p.id}>
                  <td className="num"><Link href={`/payments/${encodeURIComponent(p.id)}`} className="hover:underline"><Dt v={p.payment_date} /></Link></td>
                  <td>{p.contact?.name}{p.status === "reversed" && <> <Pill tone="bad">Reversed</Pill></>}</td>
                  <td>{methodLabel(p.method)}</td>
                  <td className="num">{p.reference ?? "—"}</td>
                  <td>{appliedTo(p.allocations_count, "invoice")}</td>
                  <td className="num text-right font-semibold">{formatMoney(p.amount, p.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/payments" picked={period.picked} next={payments.next} prev={payments.prev} />
    </>
  );
}

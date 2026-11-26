import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getPaymentsMade, getScheduledPayments, getReportPeriod } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Payments made" };

/** Module 3: what the company paid its suppliers, newest first. */
export default async function PaymentsMadePage({ searchParams }: PageProps<"/payments-made">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const [payments, scheduled] = await Promise.all([getPaymentsMade(listQuery(q, period.picked)), getScheduledPayments("scheduled").catch(() => null)]);
  const due = scheduled?.totals.filter((t) => t.due_count > 0) ?? [];

  return (
    <>
      <PageTitle eyebrow="Money out" title="Payments made" actions={<><Link href="/refunds" className="btn">Refunds</Link><Link href="/payments-made/bank-file" className="btn">Bank file</Link><Link href="/payments-made/new" className="btn btn-primary">+ Pay bills</Link></>} />
      {due.length > 0 && (
        <p role="status" className="mb-4 flex flex-wrap items-center gap-3 rounded-lg bg-warn-tint px-3 py-2 text-[13.5px] font-semibold text-warn">
          <span>Scheduled payments due: {due.map((t) => `${t.due_count} (${formatMoney(t.due, t.currency)})`).join(" · ")}. Confirm them once the money has gone out.</span>
          <Link href="/payments-made/scheduled" className="btn">Open scheduled payments</Link>
        </p>
      )}
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Payments made">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Date</th><th scope="col">Supplier</th><th scope="col">Method</th><th scope="col">Reference</th><th scope="col">Bills</th>
                <th scope="col" className="!text-right">Withheld</th><th scope="col" className="!text-right">Paid</th>
              </tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={7} className="text-muted">No payments yet.</td></tr>}
              {payments.map((p) => (
                <tr key={p.id}>
                  <td className="num"><Link href={`/payments-made/${encodeURIComponent(p.id)}`} className="hover:underline">{p.payment_date}</Link></td>
                  <td>{p.contact?.name}{p.status === "reversed" && <> <Pill tone="bad">Reversed</Pill></>}</td>
                  <td>{p.method.replace("_", " ")}</td>
                  <td className="num">{p.reference ?? "—"}</td>
                  <td>{p.allocations_count ?? 0}</td>
                  <td className="num text-right">{p.withholding === "0.00" ? "—" : formatAmount(p.withholding)}</td>
                  <td className="num text-right font-semibold">{formatMoney(p.amount, p.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/payments-made" picked={period.picked} next={payments.next} prev={payments.prev} />
    </>
  );
}

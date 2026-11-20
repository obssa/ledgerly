import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getPaymentsMade } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Payments made" };

/** Module 3: what the company paid its suppliers, newest first. */
export default async function PaymentsMadePage() {
  const payments = await getPaymentsMade();

  return (
    <>
      <PageTitle eyebrow="Money out" title="Payments made" actions={<><Link href="/refunds" className="btn">Refunds</Link><Link href="/payments-made/bank-file" className="btn">Bank file</Link><Link href="/payments-made/new" className="btn btn-primary">+ Pay bills</Link></>} />
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
    </>
  );
}

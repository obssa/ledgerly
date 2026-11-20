import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getPaymentsReceived } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Payments received" };

export default async function PaymentsPage() {
  const payments = await getPaymentsReceived();

  return (
    <>
      <PageTitle eyebrow="Money in" title="Payments received" actions={<Link href="/payments/new" className="btn btn-primary">+ Receive a payment</Link>} />
      <Card className="!p-2" label="Payments received">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Date</th><th scope="col">Customer</th><th scope="col">Method</th><th scope="col">Reference</th><th scope="col">Invoices</th><th scope="col" className="!text-right">Amount</th></tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={6} className="text-muted">No payments yet.</td></tr>}
              {payments.map((p) => (
                <tr key={p.id}>
                  <td className="num"><Link href={`/payments/${encodeURIComponent(p.id)}`} className="hover:underline">{p.payment_date}</Link></td>
                  <td>{p.contact?.name}{p.status === "reversed" && <> <Pill tone="bad">Reversed</Pill></>}</td>
                  <td>{p.method.replace("_", " ")}</td>
                  <td className="num">{p.reference ?? "—"}</td>
                  <td>{p.allocations_count ?? 0}</td>
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

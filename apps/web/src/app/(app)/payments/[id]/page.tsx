import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getMoneyAccounts, getOpenInvoices, getPaymentReceived } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { RefundPanel } from "../../refunds/RefundPanel";
import { AllocateLeft } from "./AllocateLeft";
import { BounceCheque } from "./BounceCheque";
import { ReversePayment } from "./ReversePayment";

export const metadata = { title: "Payment" };

export default async function PaymentPage({ params }: PageProps<"/payments/[id]">) {
  const { id } = await params;
  const payment = await getPaymentReceived(id);
  if (!payment) notFound();
  const canAllocate = payment.status === "posted" && payment.can.allocate && payment.unallocated !== "0.00";
  const invoices = canAllocate && payment.contact ? await getOpenInvoices(payment.contact.id, payment.currency) : [];
  const canRefund = payment.status === "posted" && payment.can.refund && payment.unallocated !== "0.00";
  const moneyAccounts = canRefund ? await getMoneyAccounts(payment.currency) : [];

  return (
    <>
      <PageTitle
        crumbs={<>Payment</>}
        title={<>{formatMoney(payment.amount, payment.currency)} from {payment.contact?.name} {payment.bounced_on ? <Pill tone="bad">Bounced</Pill> : payment.status === "reversed" ? <Pill tone="bad">Reversed</Pill> : <Pill tone="good">Recorded</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label="Payment">
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
            <div><dt className="text-muted">Date</dt><dd className="num"><Dt v={payment.payment_date} /></dd></div>
            <div><dt className="text-muted">Method</dt><dd>{payment.method.replace("_", " ")}</dd></div>
            <div><dt className="text-muted">Reference</dt><dd className="num">{payment.reference ?? "—"}</dd></div>
            {payment.cheque_number && <div><dt className="text-muted">Cheque</dt><dd className="num">{payment.cheque_number}</dd></div>}
            {payment.bounced_on && <div><dt className="text-muted">Bounced on</dt><dd className="num"><Dt v={payment.bounced_on} /></dd></div>}
            <div><dt className="text-muted">Tax withheld</dt><dd className="num">{formatAmount(payment.withholding)}</dd></div>
            <div><dt className="text-muted">Bank fee</dt><dd className="num">{formatAmount(payment.fee)}</dd></div>
            {Number(payment.fx_rate) !== 1 && <div><dt className="text-muted">Rate</dt><dd className="num">{payment.fx_rate}</dd></div>}
          </dl>
          <h2 className="mb-2 mt-5 text-[15px] font-bold">Invoices settled</h2>
          {(payment.allocations ?? []).length === 0 ? (
            <p className="text-sm text-muted">{payment.status === "reversed" ? "None: the payment was reversed and its invoices owe again." : "None: the payment is the customer's credit."}</p>
          ) : (
            <ul className="text-sm">
              {payment.allocations!.map((a) => (
                <li key={a.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                  <Link href={`/invoices/${encodeURIComponent(a.document_id)}`} className="num hover:underline">{a.document?.number}</Link>
                  <span className="num font-semibold">{formatAmount(a.amount)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        {payment.status === "posted" && (
          <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
            {canAllocate && <AllocateLeft id={payment.id} left={payment.unallocated} documents={invoices} />}
            {canRefund && payment.contact && <RefundPanel side="customer" contactId={payment.contact.id} currency={payment.currency} left={payment.unallocated} paymentId={payment.id} accounts={moneyAccounts} />}
            {payment.can.reverse && payment.method === "cheque" && <BounceCheque id={payment.id} received={payment.payment_date} />}
            {payment.can.reverse && <ReversePayment id={payment.id} />}
          </aside>
        )}
      </div>
    </>
  );
}

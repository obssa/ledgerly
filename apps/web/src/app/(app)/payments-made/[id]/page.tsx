import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getOpenBills, getPaymentMade } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { AllocateLeft } from "../../payments/[id]/AllocateLeft";
import { ReversePayment } from "../../payments/[id]/ReversePayment";

export const metadata = { title: "Payment made" };

export default async function PaymentMadePage({ params, searchParams }: PageProps<"/payments-made/[id]">) {
  const { id } = await params;
  const { cash } = await searchParams;
  const payment = await getPaymentMade(id);
  if (!payment) notFound();
  const canAllocate = payment.status === "posted" && payment.can.allocate && payment.unallocated !== "0.00";
  const bills = canAllocate && payment.contact ? await getOpenBills(payment.contact.id, payment.currency) : [];

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/payments-made" className="hover:underline">Payments made</Link> / Payment</>}
        title={<>{formatMoney(payment.amount, payment.currency)} to {payment.contact?.name} {payment.status === "reversed" ? <Pill tone="bad">Reversed</Pill> : <Pill tone="good">Recorded</Pill>}</>}
      />
      {cash === "over" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          Recorded. This cash payment is above the country&apos;s limit: payments that large must go through a bank or an authorised electronic channel.
        </p>
      )}
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label="Payment">
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
            <div><dt className="text-muted">Date</dt><dd className="num">{payment.payment_date}</dd></div>
            <div><dt className="text-muted">Method</dt><dd>{payment.method.replace("_", " ")}</dd></div>
            <div><dt className="text-muted">Reference</dt><dd className="num">{payment.reference ?? "—"}</dd></div>
            <div><dt className="text-muted">Tax withheld</dt><dd className="num">{formatAmount(payment.withholding)}</dd></div>
            <div><dt className="text-muted">Withholding receipt</dt><dd className="num">{payment.withholding_certificate ?? "—"}</dd></div>
            <div><dt className="text-muted">Bank fee</dt><dd className="num">{formatAmount(payment.fee)}</dd></div>
            {Number(payment.fx_rate) !== 1 && <div><dt className="text-muted">Rate</dt><dd className="num">{payment.fx_rate}</dd></div>}
          </dl>
          <h2 className="mb-2 mt-5 text-[15px] font-bold">Bills settled</h2>
          {(payment.allocations ?? []).length === 0 ? (
            <p className="text-sm text-muted">{payment.status === "reversed" ? "None: the payment was reversed and its bills are open again." : "None: the payment is an advance to the supplier."}</p>
          ) : (
            <ul className="text-sm">
              {payment.allocations!.map((a) => (
                <li key={a.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                  <Link href={`/bills/${encodeURIComponent(a.document_id)}`} className="num hover:underline">{a.document?.number}</Link>
                  <span className="num font-semibold">{formatAmount(a.amount)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        {payment.status === "posted" && (
          <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
            {canAllocate && <AllocateLeft id={payment.id} left={payment.unallocated} documents={bills} made />}
            {payment.can.reverse && <ReversePayment id={payment.id} made />}
          </aside>
        )}
      </div>
    </>
  );
}

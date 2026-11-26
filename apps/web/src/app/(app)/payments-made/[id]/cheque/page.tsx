import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { getPaymentMade } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { amountInWords } from "@/lib/words";

export const metadata = { title: "Cheque" };

/** A cheque written, laid out to print or copy onto the cheque leaf: payee, date, amount in figures and in words. */
export default async function ChequePage({ params }: PageProps<"/payments-made/[id]/cheque">) {
  const { id } = await params;
  const payment = await getPaymentMade(id);
  if (!payment || payment.method !== "cheque" || !payment.cheque_number) notFound();

  return (
    <>
      <p className="mb-4 mt-7 text-[13px] text-muted print:hidden">
        <Link href={`/payments-made/${encodeURIComponent(payment.id)}`} className="hover:underline">Payment made</Link> / Cheque. Print it from the browser (Ctrl+P).
      </p>
      <section aria-label="Cheque" className="max-w-[760px] rounded-[14px] border-2 border-ink bg-surface p-6 print:border print:p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="text-[13px] text-muted">Cheque <span className="num font-semibold text-ink">{payment.cheque_number}</span></div>
          <div className="text-right text-[13px] text-muted">Date <span className="num font-semibold text-ink">{payment.payment_date}</span></div>
        </div>
        {payment.status === "reversed" && <p className="mt-3 text-[15px] font-extrabold uppercase tracking-wide text-bad">Cancelled</p>}
        <div className="mt-6 border-b border-ink pb-1 text-[15px]">Pay <span className="font-bold">{payment.contact?.name}</span></div>
        <div className="mt-4 flex flex-wrap items-end gap-4">
          <div className="min-w-0 flex-1 border-b border-ink pb-1 text-[15px]">{amountInWords(payment.amount, payment.currency)}</div>
          <div className="rounded-md border-2 border-ink px-3 py-1.5 text-[17px] font-bold"><span className="mr-1 text-[13px]">{payment.currency}</span><span className="num">{formatAmount(payment.amount)}</span></div>
        </div>
        {payment.reference && <div className="mt-4 text-[13px] text-muted">For: {payment.reference}</div>}
        <div className="mt-10 flex justify-end"><div className="w-[220px] border-t border-ink pt-1 text-center text-[13px] text-muted">Authorised signature</div></div>
      </section>
    </>
  );
}

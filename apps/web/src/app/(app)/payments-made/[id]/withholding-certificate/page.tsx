import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { getCompanySettings, getPaymentMade } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { parse, toString } from "@/lib/decimal";

export const metadata = { title: "Withholding certificate" };

/** The withholding certificate handed to a supplier: who withheld, from whom, on what, how much. Laid out to print. */
export default async function WithholdingCertificatePage({ params }: PageProps<"/payments-made/[id]/withholding-certificate">) {
  const { id } = await params;
  const [payment, company] = await Promise.all([getPaymentMade(id), getCompanySettings()]);
  if (!payment || payment.withholding === "0.00") notFound();
  const withheld = parse(payment.withholding);
  const gross = parse(payment.amount) + withheld;
  const rate = gross === 0n ? "0" : (Number(withheld * 10000n / gross) / 100).toLocaleString("en", { maximumFractionDigits: 2 });
  const address = [company.address_line1, company.city].filter(Boolean).join(", ");

  return (
    <>
      <p className="mb-4 mt-7 text-[13px] text-muted print:hidden">
        <Link href={`/payments-made/${encodeURIComponent(payment.id)}`} className="hover:underline">Payment made</Link> / Withholding certificate. Print it from the browser (Ctrl+P) and sign it.
      </p>
      <section aria-label="Withholding certificate" className="max-w-[760px] rounded-[14px] border-2 border-ink bg-surface p-6 print:border print:p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <h1 className="text-[20px] font-extrabold">Withholding tax certificate</h1>
          <div className="text-right text-[13px] text-muted">No. <span className="num font-semibold text-ink">{payment.withholding_certificate ?? "—"}</span><br />Date <span className="num font-semibold text-ink">{payment.payment_date}</span></div>
        </div>
        {payment.status === "reversed" && <p className="mt-3 text-[15px] font-extrabold uppercase tracking-wide text-bad">Cancelled: the payment was reversed</p>}
        <div className="mt-6 grid gap-6 sm:grid-cols-2">
          <div>
            <div className="text-[12px] font-bold uppercase tracking-wide text-muted">Withholding agent</div>
            <div className="mt-1 text-[15px] font-bold">{company.legal_name}</div>
            <div className="text-[14px]">TIN <span className="num">{company.tin ?? "—"}</span></div>
            {address && <div className="text-[13px] text-muted">{address}</div>}
          </div>
          <div>
            <div className="text-[12px] font-bold uppercase tracking-wide text-muted">Withheld from</div>
            <div className="mt-1 text-[15px] font-bold">{payment.contact?.name ?? "—"}</div>
            <div className="text-[14px]">TIN <span className="num">{payment.contact?.tax_id ?? "—"}</span></div>
          </div>
        </div>
        <table className="tbl mt-6">
          <tbody>
            <tr><th scope="row" className="text-left">Amount of the payment, before withholding</th><td className="num text-right">{payment.currency} {formatAmount(toString(gross))}</td></tr>
            <tr><th scope="row" className="text-left">Rate</th><td className="num text-right">{rate}%</td></tr>
            <tr className="border-t-2 border-ink font-bold"><th scope="row" className="text-left">Tax withheld</th><td className="num text-right">{payment.currency} {formatAmount(payment.withholding)}</td></tr>
            <tr><th scope="row" className="text-left">Paid to the supplier</th><td className="num text-right">{payment.currency} {formatAmount(payment.amount)}</td></tr>
          </tbody>
        </table>
        {payment.reference && <p className="mt-3 text-[13px] text-muted">For: {payment.reference}</p>}
        <p className="mt-4 text-[13px]">The tax above was withheld from this payment and is paid to the tax authority for the supplier&apos;s account.</p>
        <div className="mt-12 flex flex-wrap justify-between gap-6">
          <div className="w-[220px] border-t border-ink pt-1 text-center text-[13px] text-muted">For the withholding agent: name and signature</div>
          <div className="w-[160px] border-t border-ink pt-1 text-center text-[13px] text-muted">Stamp</div>
        </div>
      </section>
    </>
  );
}

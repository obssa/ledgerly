import { Suspense } from "react";
import { ApiError, ledgerly } from "@/lib/api";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Your invoice" };

/**
 * Module 2 (099): an invoice the customer opens from a shared link (WhatsApp, or pasted anywhere): /invoice?token=…
 * No account is needed; the link shows that invoice only, what is still due on it, and its PDF.
 */
export default function InvoicePage({ searchParams }: PageProps<"/invoice">) {
  return (
    <Suspense fallback={null}>
      <Invoice searchParams={searchParams} />
    </Suspense>
  );
}

const broken = (text: string) => <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{text}</p>;

async function Invoice({ searchParams }: Pick<PageProps<"/invoice">, "searchParams">) {
  const { token } = await searchParams;
  if (typeof token !== "string" || !/^[0-9a-f]{64}$/.test(token)) {
    return <><h1 className="text-[24px] font-extrabold tracking-tight">Your invoice</h1>{broken("This link is not complete. Open the link you were sent again.")}</>;
  }
  let view;
  try {
    view = await ledgerly.publicInvoice(token);
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) {
      return <><h1 className="text-[24px] font-extrabold tracking-tight">Your invoice</h1>{broken("This link is not valid any more: ask the company to send the invoice again.")}</>;
    }
    throw error;
  }
  const i = view.invoice;

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element -- a data: URI from the API, the company's own logo */}
      {view.company.logo && <img src={view.company.logo} alt="" className="mb-2 max-h-[56px] max-w-[200px] object-contain" />}
      <p className="text-[13px] text-muted">An invoice from</p>
      <h1 className="text-[22px] font-extrabold tracking-tight">{view.company.name}</h1>
      {view.company.tin && <p className="num text-[12px] text-muted">TIN {view.company.tin}</p>}
      <dl className="mt-3 grid grid-cols-2 gap-1 text-[13px]">
        <dt className="text-muted">Invoice</dt><dd className="num text-right font-semibold">{i.number}</dd>
        <dt className="text-muted">For</dt><dd className="text-right">{view.customer}</dd>
        <dt className="text-muted">Issued</dt><dd className="num text-right">{i.issue_date}</dd>
        {i.due_date && <><dt className="text-muted">Due</dt><dd className="num text-right">{i.due_date}</dd></>}
      </dl>
      <ul className="mt-3 divide-y divide-line border-y border-line text-[13px]">
        {i.lines.map((l, n) => (
          <li key={n} className="flex justify-between gap-3 py-1.5">
            <span>{l.description} <span className="num text-muted">× {l.quantity}</span></span>
            <span className="num">{formatAmount(l.amount)}</span>
          </li>
        ))}
      </ul>
      <dl className="mt-2 text-[13px]">
        <div className="flex justify-between"><dt>Before tax</dt><dd className="num">{formatAmount(i.subtotal)}</dd></div>
        <div className="flex justify-between"><dt>Tax</dt><dd className="num">{formatAmount(i.tax_total)}</dd></div>
        <div className="mt-1 flex justify-between border-t border-ink pt-1 font-bold"><dt>Total</dt><dd className="num">{formatMoney(i.total, i.currency)}</dd></div>
        <div className="mt-1 flex justify-between text-[16px] font-extrabold"><dt>{i.paid ? "Paid" : "Still due"}</dt><dd className="num">{i.paid ? "In full" : formatMoney(i.amount_due, i.currency)}</dd></div>
      </dl>
      {i.notes && <p className="mt-3 whitespace-pre-line text-[13px]">{i.notes}</p>}
      {!i.paid && view.company.payment_details && (
        <div className="mt-3 rounded-lg border border-line p-3 text-[13px]"><strong>How to pay</strong><p className="mt-1 whitespace-pre-line">{view.company.payment_details}</p></div>
      )}
      <a className="btn btn-primary mt-4 w-full justify-center" href={`/invoice/pdf?token=${token}`}>Download the PDF</a>
      <p className="mt-3 text-[12px] text-muted">
        To pay, or with a question, contact {view.company.name}{view.company.phone ? ` on ${view.company.phone}` : ""}{view.company.email ? ` or at ${view.company.email}` : ""}.
        This link works until {view.expires_at.slice(0, 10)}.
      </p>
    </>
  );
}

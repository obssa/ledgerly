import { Suspense } from "react";
import { ApiError, ledgerly } from "@/lib/api";
import { formatAmount, formatMoney } from "@/lib/format";
import { AnswerQuote } from "./AnswerQuote";

export const metadata = { title: "Your quote" };

/**
 * Module 2: a quote the customer opens from its email (065): /quote?token=… No account is needed; the link shows that
 * quote only, and is answered once, while the quote waits for an answer.
 */
export default function QuotePage({ searchParams }: PageProps<"/quote">) {
  return (
    <Suspense fallback={null}>
      <Quote searchParams={searchParams} />
    </Suspense>
  );
}

const broken = (text: string) => <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{text}</p>;

async function Quote({ searchParams }: Pick<PageProps<"/quote">, "searchParams">) {
  const { token } = await searchParams;
  if (typeof token !== "string" || !/^[0-9a-f]{64}$/.test(token)) {
    return <><h1 className="text-[24px] font-extrabold tracking-tight">Your quote</h1>{broken("This link is not complete. Open the link from the email again.")}</>;
  }
  let view;
  try {
    view = await ledgerly.publicQuote(token);
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) {
      return <><h1 className="text-[24px] font-extrabold tracking-tight">Your quote</h1>{broken("This link is not valid any more: ask the company to send the quote again.")}</>;
    }
    throw error;
  }
  const q = view.quote;

  return (
    <>
      <p className="text-[13px] text-muted">A quote from</p>
      <h1 className="text-[22px] font-extrabold tracking-tight">{view.company.name}</h1>
      {view.company.tin && <p className="num text-[12px] text-muted">TIN {view.company.tin}</p>}
      <dl className="mt-3 grid grid-cols-2 gap-1 text-[13px]">
        <dt className="text-muted">Quote</dt><dd className="num text-right font-semibold">{q.number}</dd>
        <dt className="text-muted">For</dt><dd className="text-right">{view.customer}</dd>
        <dt className="text-muted">Issued</dt><dd className="num text-right">{q.issue_date}</dd>
        {q.valid_until && <><dt className="text-muted">Valid until</dt><dd className="num text-right">{q.valid_until}</dd></>}
      </dl>
      <ul className="mt-3 divide-y divide-line border-y border-line text-[13px]">
        {q.lines.map((l, i) => (
          <li key={i} className="flex justify-between gap-3 py-1.5">
            <span>{l.description} <span className="num text-muted">× {l.quantity}</span></span>
            <span className="num">{formatAmount(l.amount)}</span>
          </li>
        ))}
      </ul>
      <dl className="mt-2 text-[13px]">
        <div className="flex justify-between"><dt>Before tax</dt><dd className="num">{formatAmount(q.subtotal)}</dd></div>
        <div className="flex justify-between"><dt>Tax</dt><dd className="num">{formatAmount(q.tax_total)}</dd></div>
        <div className="mt-1 flex justify-between border-t border-ink pt-1 text-[16px] font-extrabold"><dt>Total</dt><dd className="num">{formatMoney(q.total, q.currency)}</dd></div>
      </dl>
      {q.notes && <p className="mt-3 whitespace-pre-line text-[13px] text-muted">{q.notes}</p>}
      {view.answer ? (
        <p role="status" className="mt-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
          {view.answer === "accepted" ? "You accepted this quote. Thank you." : "You declined this quote."}
        </p>
      ) : view.can_answer ? (
        <AnswerQuote token={token} />
      ) : (
        <p className="mt-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">This quote no longer waits for an answer. Contact {view.company.name}{view.company.email ? ` (${view.company.email})` : ""}.</p>
      )}
    </>
  );
}

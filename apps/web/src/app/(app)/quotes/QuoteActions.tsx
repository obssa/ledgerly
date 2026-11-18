"use client";

import { useActionState } from "react";
import type { QuoteDetail } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { convertQuote, emailQuote, quoteStep } from "./actions";

/** What can be done with this quote or sales order now, for this role. The API checks again on every call. */
export function QuoteActions({ doc }: { doc: QuoteDetail }) {
  const [state, action, pending] = useActionState(quoteStep, undefined);
  const [mailed, mail, mailing] = useActionState(emailQuote, undefined);
  const [converted, convert, converting] = useActionState(convertQuote, undefined);
  const quote = doc.type === "quote";
  const hidden = (step?: string) => (
    <>
      <input type="hidden" name="id" value={doc.id} />
      <input type="hidden" name="doc_kind" value={doc.type} />
      {step && <input type="hidden" name="step" value={step} />}
    </>
  );
  const flash = (s: typeof state) => (
    <>
      {s?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{s.error}</p>}
      {s?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{s.notice}</p>}
    </>
  );

  return (
    <div className="flex flex-col gap-3">
      {flash(state)}
      {flash(converted)}

      {doc.can.convert && (
        <form action={convert} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">{quote ? "The customer agreed?" : "Delivered?"}</h2>
          <p className="mt-1 text-[13px] text-muted">
            {quote
              ? "Make a sales order to deliver against, or bill it straight away. The new document is a draft with the same lines and prices."
              : "Make the invoice: a draft with the same lines and prices, dated today, to approve as usual."}
          </p>
          <details className="mt-2 text-[13px]">
            <summary className="cursor-pointer text-muted">Numbering by hand?</summary>
            <label className="field-label mt-2" htmlFor="convert-number">Number of the new document</label>
            <input className="field" id="convert-number" name="number" maxLength={40} />
          </details>
          <div className="mt-2 flex flex-wrap gap-2">
            {quote && <button type="submit" name="to" value="sales_order" className="btn" disabled={converting}>Make a sales order</button>}
            <button type="submit" name="to" value="invoice" className="btn btn-primary" disabled={converting}>{converting ? "Making…" : quote ? "Make an invoice" : "Make the invoice"}</button>
          </div>
        </form>
      )}

      {doc.can.send && (
        <form action={action}>
          {hidden("send")}
          <button type="submit" className="btn w-full justify-center" disabled={pending}>Mark as sent</button>
        </form>
      )}
      {doc.can.accept && (
        <form action={action}>
          {hidden("accept")}
          <button type="submit" className={`btn w-full justify-center ${quote ? "" : "btn-primary"}`} disabled={pending}>{quote ? "The customer accepted" : "Confirm the order"}</button>
        </form>
      )}

      {doc.can.email && (
        <form action={mail} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">Email to the customer</h2>
          {flash(mailed)}
          <p className="mt-1 text-[13px] text-muted">
            {doc.contact.email ? <>To <span className="font-semibold">{doc.contact.email}</span>, with its lines and totals; replies come to the company.</> : "The customer has no email address yet: add one on their page."}
            {quote && doc.status === "draft" ? " Sending marks it as sent and numbers it." : ""}
          </p>
          <label className="field-label mt-2" htmlFor="quote-note">Message (optional)</label>
          <input className="field" id="quote-note" name="note" maxLength={500} />
          <button type="submit" className="btn mt-2" disabled={mailing || !doc.contact.email}>{mailing ? "Sending…" : "Send"}</button>
        </form>
      )}

      {doc.can.extend && (
        <form action={action} className="rounded-xl border border-line p-3">
          {hidden("extend")}
          <label className="field-label" htmlFor="valid_until">Offer it again until</label>
          <input className="field" id="valid_until" name="valid_until" type="date" min={todayIn()} />
          <button type="submit" className="btn mt-2" disabled={pending}>Extend</button>
        </form>
      )}
      {doc.can.decline && (
        <form action={action} className="rounded-xl border border-line p-3">
          {hidden("decline")}
          <label className="field-label" htmlFor="decline-note">The customer declined: why (optional)</label>
          <input className="field" id="decline-note" name="note" maxLength={500} />
          <button type="submit" className="btn mt-2" disabled={pending}>Mark as declined</button>
        </form>
      )}
      {doc.can.cancel && (
        <form action={action} className="rounded-xl border border-line p-3">
          {hidden("cancel")}
          <label className="field-label" htmlFor="cancel-note">Cancel: reason (optional)</label>
          <input className="field" id="cancel-note" name="note" maxLength={500} />
          <button type="submit" className="btn mt-2" disabled={pending}>{quote ? "Cancel the quote" : "Cancel the order"}</button>
        </form>
      )}
    </div>
  );
}

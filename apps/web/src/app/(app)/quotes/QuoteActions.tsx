"use client";

import { useActionState } from "react";
import type { QuoteDetail } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { convertQuote, deliverGoods, emailQuote, quoteStep } from "./actions";

/** What can be done with this quote or sales order now, for this role. The API checks again on every call. */
export function QuoteActions({ doc }: { doc: QuoteDetail }) {
  const [state, action, pending] = useActionState(quoteStep, undefined);
  const [mailed, mail, mailing] = useActionState(emailQuote, undefined);
  const [converted, convert, converting] = useActionState(convertQuote, undefined);
  const [delivered, deliver, delivering] = useActionState(deliverGoods, undefined);
  const quote = doc.type === "quote";
  // 067: a confirmed order is invoiced for what goes out now; each line starts at what is left of it. 071: a line whose
  // goods leave on delivery notes starts at what left and is not yet invoiced.
  const toInvoice = (id: string) => doc.delivery?.[id]?.billable ?? doc.delivery?.[id]?.remaining ?? "0";
  const open = !quote && doc.status === "accepted" && doc.delivery
    ? doc.lines.filter((l) => l.id && Number(toInvoice(l.id)) > 0) : [];
  const leaving = doc.lines.filter((l) => l.id && doc.leaving?.[l.id]);
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
      {flash(delivered)}

      {doc.can.deliver && (
        <form action={deliver} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">Goods leaving?</h2>
          <p className="mt-1 text-[13px] text-muted">
            Record what goes to the customer on a delivery note: it leaves stock today at its cost. The invoice then bills it without moving it again.
          </p>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <div>
              <label className="field-label" htmlFor="delivered-on">Delivered on</label>
              <input className="field num" id="delivered-on" name="delivered_on" type="date" defaultValue={todayIn()} max={todayIn()} required />
            </div>
            <div>
              <label className="field-label" htmlFor="delivered-ref">Waybill or their order</label>
              <input className="field" id="delivered-ref" name="reference" maxLength={100} />
            </div>
          </div>
          <fieldset className="mt-2">
            <legend className="sr-only">Quantities leaving</legend>
            {leaving.map((l) => (
              <div key={l.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-[13px]">
                <label htmlFor={`leave-${l.id}`} className="min-w-0 flex-1">{l.description} <span className="text-muted">({doc.leaving?.[l.id ?? ""]} to deliver)</span></label>
                <input className="field num !w-[96px] text-right" id={`leave-${l.id}`} name={`receive_${l.id}`} defaultValue={doc.leaving?.[l.id ?? ""]}
                  inputMode="decimal" pattern="\d+(\.\d{1,2})?" aria-describedby="leave-help" />
              </div>
            ))}
            <p id="leave-help" className="mt-1 text-[12px] text-muted">Type 0 for a line that does not go now. Only stock items go on a delivery note.</p>
          </fieldset>
          <label className="field-label mt-2" htmlFor="delivered-note">Note (optional)</label>
          <input className="field" id="delivered-note" name="note" maxLength={500} />
          <button type="submit" className="btn btn-primary mt-2" disabled={delivering}>{delivering ? "Recording…" : "Record the delivery note"}</button>
        </form>
      )}

      {doc.can.convert && (
        <form action={convert} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">{quote ? "The customer agreed?" : "Delivered?"}</h2>
          <p className="mt-1 text-[13px] text-muted">
            {quote
              ? "Make a sales order to deliver against, or bill it straight away. The new document is a draft with the same lines and prices."
              : open.length > 0 && doc.invoices && doc.invoices.length > 0
                ? "Invoice what goes out now: a draft dated today for the quantities below. What stays is the back-order."
                : "Invoice what goes out now: a draft with the same prices, dated today, to approve as usual. Lower a quantity to deliver part of it; the rest stays on order."}
          </p>
          {open.length > 0 && (
            <fieldset className="mt-2">
              <legend className="sr-only">Quantities to deliver now</legend>
              {open.map((l) => {
                const d = doc.delivery?.[l.id ?? ""];
                const shipped = Number(d?.shipped ?? 0) > 0;
                return (
                  <div key={l.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-[13px]">
                    <label htmlFor={`deliver-${l.id}`} className="min-w-0 flex-1">{l.description} <span className="text-muted">({toInvoice(l.id ?? "")} {shipped ? "delivered, not invoiced" : "left"})</span></label>
                    <input className="field num !w-[96px] text-right" id={`deliver-${l.id}`} name={`deliver_${l.id}`} defaultValue={toInvoice(l.id ?? "")}
                      inputMode="decimal" pattern="\d+(\.\d{1,2})?" aria-describedby="deliver-help" />
                  </div>
                );
              })}
              <p id="deliver-help" className="mt-1 text-[12px] text-muted">Type 0 for a line that does not go out now.</p>
            </fieldset>
          )}
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
      {doc.can.close && (
        <form action={action} className="rounded-xl border border-line p-3">
          {hidden("close")}
          <h2 className="text-[15px] font-bold">Nothing more to deliver?</h2>
          <p className="mt-1 text-[13px] text-muted">Close the order: what is left is no longer owed to the customer, nor reserved in stock.</p>
          <label className="field-label mt-2" htmlFor="close-note">Reason (optional)</label>
          <input className="field" id="close-note" name="note" maxLength={500} />
          <button type="submit" className="btn mt-2" disabled={pending}>Close the order</button>
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

"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import type { PurchaseOrderDetail } from "@/lib/api";
import { billPurchaseOrder, purchaseOrderStep, receiveGoods } from "./actions";

/** What can be done with this purchase order now, for this role. The API checks again on every call. */
export function PurchaseOrderActions({ doc, today }: { doc: PurchaseOrderDetail; today: string }) {
  const [state, action, pending] = useActionState(purchaseOrderStep, undefined);
  const [billed, bill, billing] = useActionState(billPurchaseOrder, undefined);
  const [received, receive, receiving] = useActionState(receiveGoods, undefined);
  // 069: a line whose goods come in on goods received notes is billed for what arrived and is not billed yet.
  const open = doc.lines.filter((l) => l.id && Number(doc.delivery[l.id]?.billable ?? 0) > 0);
  const arriving = doc.lines.filter((l) => l.id && doc.arriving[l.id]);
  const hidden = (step?: string) => (
    <>
      <input type="hidden" name="id" value={doc.id} />
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
      {flash(billed)}
      {flash(received)}

      {doc.can.receive && (
        <form action={receive} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">Goods arrived?</h2>
          <p className="mt-1 text-[13px] text-muted">
            Record what came: it goes into stock at the order&apos;s price, as goods received not yet billed. The supplier&apos;s bill then clears it.
          </p>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <div>
              <label className="field-label" htmlFor="received-on">Arrived on</label>
              <DateInput className="field num" id="received-on" name="received_on" defaultValue={today} max={today} required />
            </div>
            <div>
              <label className="field-label" htmlFor="received-ref">Their delivery note</label>
              <input className="field" id="received-ref" name="reference" maxLength={100} />
            </div>
          </div>
          <fieldset className="mt-2">
            <legend className="sr-only">Quantities that arrived</legend>
            {arriving.map((l) => (
              <div key={l.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-[13px]">
                <label htmlFor={`receive-${l.id}`} className="min-w-0 flex-1">{l.description} <span className="text-muted">({doc.arriving[l.id ?? ""]} to come)</span></label>
                <input className="field num !w-[96px] text-right" id={`receive-${l.id}`} name={`receive_${l.id}`} defaultValue={doc.arriving[l.id ?? ""]}
                  inputMode="decimal" pattern="\d+(\.\d{1,2})?" aria-describedby="receive-help" />
                <input className="field !w-[96px] text-[13px]" aria-label={`Batch of ${l.description}`} name={`batch_${l.id}`} placeholder="Batch" maxLength={60} />
                <DateInput className="field !w-[140px] text-[13px]" aria-label={`Expiry of ${l.description}`} name={`expires_${l.id}`} />
              </div>
            ))}
            <p id="receive-help" className="mt-1 text-[12px] text-muted">Type 0 for a line that did not come. Only stock items are received. Batch and expiry: for items tracked by batch.</p>
          </fieldset>
          <label className="field-label mt-2" htmlFor="received-note">Note (optional)</label>
          <input className="field" id="received-note" name="note" maxLength={500} />
          <button type="submit" className="btn btn-primary mt-2" disabled={receiving}>{receiving ? "Recording…" : "Record the goods received"}</button>
        </form>
      )}

      {doc.can.issue && (
        <form action={action}>
          {hidden("issue")}
          <button type="submit" className="btn btn-primary w-full justify-center" disabled={pending}>Issue to the supplier</button>
        </form>
      )}

      {doc.can.bill && (
        <form action={bill} className="rounded-xl border border-line p-3">
          {hidden()}
          <h2 className="text-[15px] font-bold">The supplier&apos;s invoice came in?</h2>
          <p className="mt-1 text-[13px] text-muted">
            Make the bill for what it covers: a draft dated today with the order&apos;s prices, to check against their invoice and approve as usual.
            What is not billed stays on order.
          </p>
          <fieldset className="mt-2">
            <legend className="sr-only">Quantities on this bill</legend>
            {open.map((l) => {
              const d = doc.delivery[l.id ?? ""];
              const received = Number(d?.received ?? 0) > 0;
              return (
                <div key={l.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-[13px]">
                  <label htmlFor={`deliver-${l.id}`} className="min-w-0 flex-1">{l.description} <span className="text-muted">({d?.billable} {received ? "received, not billed" : "to come"})</span></label>
                  <input className="field num !w-[96px] text-right" id={`deliver-${l.id}`} name={`deliver_${l.id}`} defaultValue={d?.billable}
                    inputMode="decimal" pattern="\d+(\.\d{1,2})?" aria-describedby="bill-help" />
                </div>
              );
            })}
            <p id="bill-help" className="mt-1 text-[12px] text-muted">Type 0 for a line this invoice does not cover.</p>
          </fieldset>
          <button type="submit" className="btn btn-primary mt-2" disabled={billing}>{billing ? "Making…" : "Make the bill"}</button>
        </form>
      )}

      {doc.can.close && (
        <form action={action} className="rounded-xl border border-line p-3">
          {hidden("close")}
          <h2 className="text-[15px] font-bold">Nothing more coming?</h2>
          <p className="mt-1 text-[13px] text-muted">Close the order: what is left is no longer on order from the supplier.</p>
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
          <button type="submit" className="btn mt-2" disabled={pending}>Cancel the order</button>
        </form>
      )}
    </div>
  );
}

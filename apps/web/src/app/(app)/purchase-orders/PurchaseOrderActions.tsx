"use client";

import { useActionState } from "react";
import type { PurchaseOrderDetail } from "@/lib/api";
import { billPurchaseOrder, purchaseOrderStep } from "./actions";

/** What can be done with this purchase order now, for this role. The API checks again on every call. */
export function PurchaseOrderActions({ doc }: { doc: PurchaseOrderDetail }) {
  const [state, action, pending] = useActionState(purchaseOrderStep, undefined);
  const [billed, bill, billing] = useActionState(billPurchaseOrder, undefined);
  const open = doc.lines.filter((l) => l.id && Number(doc.delivery[l.id]?.remaining ?? 0) > 0);
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
              return (
                <div key={l.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-[13px]">
                  <label htmlFor={`deliver-${l.id}`} className="min-w-0 flex-1">{l.description} <span className="text-muted">({d?.remaining} to come)</span></label>
                  <input className="field num !w-[96px] text-right" id={`deliver-${l.id}`} name={`deliver_${l.id}`} defaultValue={d?.remaining}
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

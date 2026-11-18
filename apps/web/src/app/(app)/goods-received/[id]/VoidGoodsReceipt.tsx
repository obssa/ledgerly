"use client";

import { useActionState } from "react";
import { voidGoodsReceipt } from "../../purchase-orders/actions";

/** Voids a goods received note; the API refuses while its goods are billed (void the bill first). */
export function VoidGoodsReceipt({ id }: { id: string }) {
  const [state, action, pending] = useActionState(voidGoodsReceipt, undefined);
  return (
    <form action={action} className="rounded-xl border border-line p-3">
      <input type="hidden" name="id" value={id} />
      {state?.error && <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <h2 className="text-[15px] font-bold">Recorded by mistake?</h2>
      <p className="mt-1 text-[13px] text-muted">Voiding takes its goods out of stock again and reverses its entry. Not possible once its goods are billed.</p>
      <label className="field-label mt-2" htmlFor="void-reason">Reason (optional)</label>
      <input className="field" id="void-reason" name="reason" maxLength={500} />
      <button type="submit" className="btn mt-2" disabled={pending}>Void the note</button>
    </form>
  );
}

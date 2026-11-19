"use client";

import { useActionState } from "react";
import { todayIn } from "@/lib/dates";
import { repeatInvoice } from "../actions";

/** 077: repeat this invoice on a schedule: the customer, lines and notes as they are now. */
export function RepeatInvoice({ invoiceId, canPost }: { invoiceId: string; canPost: boolean }) {
  const [state, action, pending] = useActionState(repeatInvoice, undefined);
  const today = todayIn();
  return (
    <details className="rounded-xl border border-line p-3">
      <summary className="cursor-pointer text-[15px] font-bold">Repeat this invoice</summary>
      <form action={action} className="mt-2 grid gap-2">
        <input type="hidden" name="id" value={invoiceId} />
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        <p className="text-[13px] text-muted">A new invoice with these lines, for this customer, on each date: dated that day and due by their terms.</p>
        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="field-label" htmlFor="repeat-frequency">Every</label>
            <select className="field" id="repeat-frequency" name="frequency" defaultValue="monthly">
              <option value="weekly">Week</option><option value="monthly">Month</option><option value="quarterly">Quarter</option><option value="yearly">Year</option>
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="repeat-next">First on</label>
            <input className="field num" id="repeat-next" name="next_on" type="date" defaultValue={today} min={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="repeat-ends">Until (optional)</label>
            <input className="field num" id="repeat-ends" name="ends_on" type="date" min={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="repeat-name">Name (optional)</label>
            <input className="field" id="repeat-name" name="name" maxLength={120} placeholder="Monthly retainer" />
          </div>
        </div>
        {canPost && (
          <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" name="post" className="size-4" /> Post each invoice (otherwise a draft to check and approve)</label>
        )}
        <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Setting up…" : "Repeat it"}</button>
      </form>
    </details>
  );
}

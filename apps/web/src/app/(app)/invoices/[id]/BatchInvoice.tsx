"use client";

import { useActionState } from "react";
import { todayIn } from "@/lib/dates";
import { batchInvoice } from "../actions";

/** Batch invoices: these lines and notes invoiced to several customers at once, one invoice each, due by their terms. */
export function BatchInvoice({ invoiceId, customers, canPost }: { invoiceId: string; customers: { id: string; name: string }[]; canPost: boolean }) {
  const [state, action, pending] = useActionState(batchInvoice, undefined);
  const today = todayIn();
  return (
    <details className="rounded-xl border border-line p-3">
      <summary className="cursor-pointer text-[15px] font-bold">Send to more customers</summary>
      <form action={action} className="mt-2 grid gap-2">
        <input type="hidden" name="id" value={invoiceId} />
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
        <p className="text-[13px] text-muted">A new invoice with these lines and notes for each customer ticked, dated the same day and due by their own terms. All are made, or none.</p>
        <fieldset className="max-h-[220px] overflow-y-auto rounded-lg border border-line p-2">
          <legend className="sr-only">Customers</legend>
          {customers.map((c) => (
            <label key={c.id} className="flex items-center gap-2 py-0.5 text-[13px]">
              <input type="checkbox" name="contact_ids" value={c.id} /> {c.name}
            </label>
          ))}
        </fieldset>
        <div>
          <label className="field-label" htmlFor="batch-date">Dated</label>
          <input className="field num" id="batch-date" name="issue_date" type="date" defaultValue={today} />
        </div>
        {canPost && (
          <label className="flex items-center gap-2 text-[13px]" htmlFor="batch-post">
            <input type="checkbox" id="batch-post" name="post" /> Post them now (otherwise drafts to review)
          </label>
        )}
        <button type="submit" className="btn justify-center" disabled={pending}>{pending ? "Making…" : "Make the invoices"}</button>
      </form>
    </details>
  );
}

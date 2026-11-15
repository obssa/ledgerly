"use client";

import { useActionState } from "react";
import type { InvoiceDetail } from "@/lib/api";
import { invoiceAction } from "../actions";

/** What can be done with this invoice now, for this role. The API checks again on every call. */
export function InvoiceActions({ invoice }: { invoice: InvoiceDetail }) {
  const [state, action, pending] = useActionState(invoiceAction, undefined);
  const open = invoice.status === "draft" || invoice.status === "awaiting_approval";
  const posted = invoice.status === "posted";
  // A registered e-invoice is corrected with a credit note, not voided (tax-authority rule; the API refuses too).
  const registered = invoice.einvoice_submissions?.some((s) => s.status === "cleared" || s.status === "submitted") ?? false;

  return (
    <div className="flex flex-col gap-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}

      {open && invoice.can.approve && (
        <form action={action}>
          <input type="hidden" name="id" value={invoice.id} />
          <input type="hidden" name="action" value="approve" />
          <button type="submit" className="btn btn-primary w-full justify-center" disabled={pending}>{pending ? "Posting…" : "Approve and post"}</button>
        </form>
      )}
      {invoice.status === "draft" && invoice.can.edit && !invoice.can.approve && (
        <form action={action}>
          <input type="hidden" name="id" value={invoice.id} />
          <input type="hidden" name="action" value="submit" />
          <button type="submit" className="btn w-full justify-center" disabled={pending}>Send for approval</button>
        </form>
      )}
      {posted && registered && (
        <p className="rounded-xl border border-line p-3 text-[13px] text-muted">
          Registered with the tax authority: correct it with a credit note and, if needed, a new invoice.
        </p>
      )}
      {posted && !registered && invoice.can.void && invoice.type !== "credit_note" && (
        <form action={action} className="rounded-xl border border-line p-3">
          <input type="hidden" name="id" value={invoice.id} />
          <input type="hidden" name="action" value="void" />
          <label className="field-label" htmlFor="reason">Void: reason</label>
          <input className="field" id="reason" name="reason" minLength={3} maxLength={500} />
          <p className="mt-1.5 text-[13px] text-muted">The entry is reversed and the number kept. A registered e-invoice is corrected with a credit note instead.</p>
          <button type="submit" className="btn mt-2" disabled={pending}>Void invoice</button>
        </form>
      )}
    </div>
  );
}

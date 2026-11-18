"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { emailInvoice } from "../actions";

/** Module 2: print the invoice or save it as PDF (the browser's print dialog), or email it to the customer. */
export function PrintInvoice() {
  return <button type="button" className="btn print:hidden" onClick={() => window.print()}>Print or save as PDF</button>;
}

export function SendInvoice({ invoiceId, email, label }: { invoiceId: string; email: string | null; label: string }) {
  const [state, action, pending] = useActionState(emailInvoice, undefined);
  return (
    <Card label={`Send the ${label.toLowerCase()}`}>
      <h2 className="text-[17px] font-bold">Send to the customer</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {email ? (
        <form action={action} className="mt-2 flex flex-col gap-2">
          <input type="hidden" name="id" value={invoiceId} />
          <p className="text-[13px] text-muted">By email to {email}, with its lines and totals; replies come to the company&apos;s email.</p>
          <label className="field-label" htmlFor="send-note">Message (optional)</label>
          <textarea className="field min-h-[64px]" id="send-note" name="note" maxLength={500} placeholder="Thank you for your order." />
          <button type="submit" className="btn justify-center" disabled={pending}>{pending ? "Sending…" : `Email the ${label.toLowerCase()}`}</button>
        </form>
      ) : (
        <p className="mt-1.5 text-[13px] text-muted">The customer has no email address: add one on the customer to send it.</p>
      )}
    </Card>
  );
}

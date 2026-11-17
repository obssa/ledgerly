"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceReminderSent } from "@/lib/api";
import { sessionTime } from "@/lib/sessions";
import { remindInvoice } from "../actions";

/** Module 2, collections: the payment reminders this invoice's customer was sent, and sending one now. */
export function Reminders({ invoiceId, sent, canSend }: { invoiceId: string; sent: InvoiceReminderSent[]; canSend: boolean }) {
  const [state, action, pending] = useActionState(remindInvoice, undefined);
  return (
    <Card label="Payment reminders">
      <h2 className="text-[17px] font-bold">Payment reminders</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {sent.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">None sent yet. Turn on automatic reminders in Settings.</p>
      ) : (
        <ul className="mt-2 flex flex-col gap-1 text-[13px]">
          {sent.map((r, i) => (
            <li key={i}>
              <span className="num">{sessionTime(r.sent_at)}</span> · {r.kind === "scheduled" ? `automatic, ${r.step} days after due` : "sent by hand"} · {r.sent_to}
            </li>
          ))}
        </ul>
      )}
      {canSend && (
        <form action={action} className="mt-3">
          <input type="hidden" name="id" value={invoiceId} />
          <button type="submit" className="btn w-full justify-center" disabled={pending}>{pending ? "Sending…" : "Send a reminder now"}</button>
        </form>
      )}
    </Card>
  );
}

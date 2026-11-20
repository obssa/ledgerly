"use client";

import { useActionState } from "react";
import { shareOnWhatsApp, type ShareState } from "@/app/(app)/share-actions";

/**
 * Module 2: send an invoice or a quote by WhatsApp. The first step makes the link and the message; the second opens
 * WhatsApp with the message written, to the customer's number when it is in international form. Nothing goes until the
 * sender presses send in WhatsApp.
 */
export function ShareWhatsApp({ kind, id, phone, draft }: { kind: "invoice" | "quote"; id: string; phone: string | null; draft?: boolean }) {
  const [state, action, pending] = useActionState<ShareState | undefined, FormData>(shareOnWhatsApp, undefined);
  return (
    <div className="rounded-xl border border-line p-3">
      <h2 className="text-[15px] font-bold">Send by WhatsApp</h2>
      {state?.error && <p role="alert" className="mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.whatsapp ? (
        <div className="mt-2 grid gap-2">
          <p className="text-[13px] text-muted">The message is ready, with a link to the {kind}{kind === "invoice" ? " and its PDF" : " to accept or decline it"}. It works until {state.expires}.</p>
          <a className="btn btn-primary justify-center" href={state.whatsapp} target="_blank" rel="noopener noreferrer">Open WhatsApp{state.to ? "" : " and choose the chat"}</a>
          <label className="field-label" htmlFor={`share-${id}`}>Or copy the link</label>
          <input className="field text-[12px]" id={`share-${id}`} readOnly value={state.url} onFocus={(e) => e.currentTarget.select()} />
        </div>
      ) : (
        <form action={action} className="mt-1.5 grid gap-2">
          <input type="hidden" name="kind" value={kind} />
          <input type="hidden" name="id" value={id} />
          <p className="text-[13px] text-muted">
            {phone ? <>To {phone}{phone.trim().startsWith("+") ? "" : " (not in international form, so you choose the chat)"}.</> : "The customer has no phone number: you choose the chat in WhatsApp."}
            {draft ? " Sharing marks the quote as sent and numbers it." : ""}
          </p>
          <button type="submit" className="btn justify-center" disabled={pending}>{pending ? "Preparing…" : "Write the WhatsApp message"}</button>
        </form>
      )}
    </div>
  );
}

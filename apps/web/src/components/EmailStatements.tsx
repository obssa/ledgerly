"use client";

import { useActionState } from "react";
import { emailStatements } from "@/app/(app)/customers/statements/actions";
import type { StatementType } from "@/lib/api";

/** Send each customer shown their statement by email (QuickBooks: Create statements, Send); those without an address are named. */
export function EmailStatements({ ids, type, from, to, withEmail }: { ids: string[]; type: StatementType; from: string; to: string; withEmail: number }) {
  const [state, action, pending] = useActionState(emailStatements, undefined);
  return (
    <form action={action} className="mb-4 flex flex-wrap items-end gap-3 rounded-[14px] border border-line bg-surface p-4 print:hidden">
      {ids.map((id) => <input key={id} type="hidden" name="contact_ids" value={id} />)}
      <input type="hidden" name="type" value={type} />
      <input type="hidden" name="from" value={from} />
      <input type="hidden" name="to" value={to} />
      <div className="min-w-[240px] flex-1">
        <label className="field-label" htmlFor="statement_note">Message with the statements (optional)</label>
        <input className="field" id="statement_note" name="note" maxLength={1000} />
      </div>
      <button type="submit" className="btn btn-primary" disabled={pending || withEmail === 0}>
        {pending ? "Sending…" : `Email ${withEmail} ${withEmail === 1 ? "statement" : "statements"}`}
      </button>
      {withEmail < ids.length && <p className="w-full text-[13px] text-muted">{ids.length - withEmail} without an email address on file are not sent.</p>}
      {state?.error && <p role="alert" className="w-full text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="w-full text-[13px] font-semibold text-good">{state.notice}</p>}
    </form>
  );
}

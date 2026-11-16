"use client";

import { useActionState, useState } from "react";
import type { Account, Dimension } from "@/lib/api";
import { emptyLine, type JournalLineInput } from "@/lib/journal";
import { JournalLines } from "../journal/JournalForms";
import { setPeriodLock } from "../settings/actions";
import { closeYear, finaliseAudit, postAdjustment, reopenYear } from "./actions";

/** Close the books through the period end checked above. Warnings inform the close; they do not block it. */
export function CloseForm({ periodEnd, warnings }: { periodEnd: string; warnings: number }) {
  const [state, action, pending] = useActionState(setPeriodLock, undefined);
  return (
    <form action={action} className="grid gap-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="books_closed_until" value={periodEnd} />
      <p className="text-[14px]">
        Nothing can be posted on or before <span className="num font-semibold">{periodEnd}</span> once the books are closed; an owner or admin can reopen them with a reason.
        {warnings > 0 && <> The {warnings} open item{warnings === 1 ? "" : "s"} above will be kept in the record of the close.</>}
      </p>
      <div><button type="submit" className={`btn ${warnings === 0 ? "btn-primary" : ""}`} disabled={pending}>{pending ? "Closing…" : `Close the books through ${periodEnd}`}</button></div>
    </form>
  );
}

/** Close the fiscal year: its profit or loss to retained earnings, its periods locked, the next year opened. */
export function CloseYear({ id, endsOn, netProfit }: { id: string; endsOn: string; netProfit: string }) {
  const [state, action, pending] = useActionState(closeYear, undefined);
  return (
    <form action={action} className="grid gap-2">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="id" value={id} />
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Closing…" : `Close the year to ${endsOn}`}</button></div>
      <p className="text-[13px] text-muted">The {netProfit.startsWith("-") ? "loss" : "profit"} of the year goes to retained earnings in one closing entry dated {endsOn}; the books are locked through that day and the next year opens.</p>
    </form>
  );
}

/** Reopen the latest closed year (owners and admins), saying why: its closing entry is reversed and its periods open. */
export function ReopenYear({ id }: { id: string }) {
  const [state, action, pending] = useActionState(reopenYear, undefined);
  return (
    <form action={action} className="flex flex-wrap items-end gap-2">
      {state?.error && <p role="alert" className="w-full rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="w-full rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="id" value={id} />
      <div className="min-w-[260px] flex-1">
        <label className="field-label" htmlFor={`reopen-${id}`}>Why reopen it</label>
        <input className="field" id={`reopen-${id}`} name="reason" maxLength={500} />
      </div>
      <button type="submit" className="btn" disabled={pending}>Reopen the year</button>
    </form>
  );
}

/** An audit adjustment: the auditor's reference and balanced lines, dated the year's last day. */
export function AdjustmentForm({ id, endsOn, accounts, dimensions }: { id: string; endsOn: string; accounts: Account[]; dimensions: Dimension[] }) {
  const [state, action, pending] = useActionState(postAdjustment, undefined);
  const [lines, setLines] = useState<JournalLineInput[]>([emptyLine(), emptyLine()]);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-4">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="id" value={id} />
      <div className="max-w-[600px]">
        <label className="field-label" htmlFor="adj_memo">Memo</label>
        <input className={`field ${bad("memo") ? "!border-bad" : ""}`} id="adj_memo" name="memo" maxLength={500} placeholder="The auditor's reference, such as AJE 3: audit fee accrued" />
      </div>
      <JournalLines lines={lines} setLines={setLines} accounts={accounts} dimensions={dimensions} bad={bad} />
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Posting…" : "Post adjustment"}</button>
        <span className="text-[13px] text-muted">Dated <span className="num">{endsOn}</span>. The year stays closed: its closing entry is posted again with the adjustment in it.</span>
      </div>
    </form>
  );
}

/** Sign the audit off: the year takes no more adjustments and can no longer be reopened. */
export function FinaliseAudit({ id }: { id: string }) {
  const [state, action, pending] = useActionState(finaliseAudit, undefined);
  return (
    <form action={action} className="grid gap-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="id" value={id} />
      <label className="flex items-start gap-2 text-[14px]">
        <input type="checkbox" name="confirm" value="yes" className="mt-1" />
        <span>The auditor has signed off. After this the year takes no more adjustments and cannot be reopened; anything found later is corrected in the next year.</span>
      </label>
      <div><button type="submit" className="btn" disabled={pending}>{pending ? "Signing off…" : "Sign the audit off"}</button></div>
    </form>
  );
}

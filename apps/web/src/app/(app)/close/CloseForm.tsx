"use client";

import { useActionState } from "react";
import { setPeriodLock } from "../settings/actions";
import { closeYear, reopenYear } from "./actions";

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

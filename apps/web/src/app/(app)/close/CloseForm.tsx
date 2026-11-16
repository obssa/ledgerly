"use client";

import { useActionState } from "react";
import { setPeriodLock } from "../settings/actions";

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

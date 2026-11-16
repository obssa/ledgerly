"use client";

import { useActionState } from "react";
import { postRevaluation } from "./actions";

/** Post the revaluation previewed above, at its date and rates. */
export function PostRevaluation({ asOf, rates }: { asOf: string; rates: Record<string, string> }) {
  const [state, action, pending] = useActionState(postRevaluation, undefined);
  return (
    <form action={action} className="flex flex-wrap items-center gap-3">
      {state?.error && <p role="alert" className="w-full rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="w-full rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <input type="hidden" name="as_of" value={asOf} />
      {Object.entries(rates).map(([c, r]) => <input key={c} type="hidden" name={`rate:${c}`} value={r} />)}
      <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Posting…" : `Post the revaluation at ${asOf}`}</button>
      <span className="text-[13px] text-muted">It is reversed on the next day, so documents keep their own rates.</span>
    </form>
  );
}

"use client";

import { useActionState } from "react";
import { answerQuote } from "./actions";

/** Accept or decline, with the name the answer is recorded under. */
export function AnswerQuote({ token }: { token: string }) {
  const [state, action, pending] = useActionState(answerQuote, undefined);
  if (state?.notice) return <p role="status" className="mt-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return (
    <form action={action} className="mt-4 grid gap-2.5">
      <input type="hidden" name="token" value={token} />
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      <div>
        <label className="field-label" htmlFor="name">Your name</label>
        <input className="field" id="name" name="name" autoComplete="name" maxLength={120} required />
      </div>
      <div>
        <label className="field-label" htmlFor="note">A note to the company (optional)</label>
        <input className="field" id="note" name="note" maxLength={300} />
      </div>
      <div className="flex gap-2">
        <button type="submit" name="answer" value="accepted" className="btn btn-primary flex-1 justify-center" disabled={pending}>Accept the quote</button>
        <button type="submit" name="answer" value="declined" className="btn flex-1 justify-center" disabled={pending}>Decline</button>
      </div>
    </form>
  );
}

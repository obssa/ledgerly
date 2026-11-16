"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { reverseRefund } from "./actions";

/** A refund that did not go through: the entry is reversed and the credit is back. Needs a reason. */
export function ReverseRefund({ id }: { id: string }) {
  const [state, action, pending] = useActionState(reverseRefund, undefined);
  const today = new Date().toISOString().slice(0, 10);
  return (
    <Card label="Reverse refund">
      <h2 className="text-[17px] font-bold">Reverse this refund</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
        <input type="hidden" name="id" value={id} />
        <div><label className="field-label" htmlFor="rr_date">Date</label><input className="field" id="rr_date" name="date" type="date" defaultValue={today} /></div>
        <div><label className="field-label" htmlFor="rr_reason">Reason</label><input className="field" id="rr_reason" name="reason" maxLength={500} /></div>
        <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Reversing…" : "Reverse refund"}</button>
      </form>
    </Card>
  );
}

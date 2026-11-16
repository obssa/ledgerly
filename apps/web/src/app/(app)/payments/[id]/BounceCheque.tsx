"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { bounceCheque } from "../actions";

/** A cheque received that the bank returned: reversed on that day, its invoices owe again, and the bank's charge booked. */
export function BounceCheque({ id, received }: { id: string; received: string }) {
  const [state, action, pending] = useActionState(bounceCheque, undefined);
  const today = new Date().toISOString().slice(0, 10);

  return (
    <Card label="Cheque bounced">
      <h2 className="text-[17px] font-bold">The cheque bounced</h2>
      <p className="mt-1.5 text-[13px] text-muted">
        The payment is reversed on the day the bank returned the cheque and its invoices owe again. The deposit and the bounce both stay on the bank statement.
      </p>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
        <input type="hidden" name="id" value={id} />
        <div>
          <label className="field-label" htmlFor="bounce_date">Returned by the bank on</label>
          <input className="field" id="bounce_date" name="date" type="date" min={received} max={today} defaultValue={today} />
        </div>
        <div>
          <label className="field-label" htmlFor="bounce_fee">The bank&apos;s charge</label>
          <input className="field num" id="bounce_fee" name="fee" inputMode="decimal" placeholder="0.00" />
        </div>
        <div>
          <label className="field-label" htmlFor="bounce_reason">Reason</label>
          <input className="field" id="bounce_reason" name="reason" maxLength={500} placeholder="Insufficient funds" />
        </div>
        <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Recording…" : "Record bounced cheque"}</button>
      </form>
    </Card>
  );
}

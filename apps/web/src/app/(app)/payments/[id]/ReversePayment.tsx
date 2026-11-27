"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { reversePaymentMade } from "../../payments-made/actions";
import { reversePayment } from "../actions";
import { todayIn } from "@/lib/dates";

/** A bounced cheque or returned transfer: the entry is reversed and the invoices (or bills) are open again. Needs a reason. */
export function ReversePayment({ id, made = false }: { id: string; made?: boolean }) {
  const [state, action, pending] = useActionState(made ? reversePaymentMade : reversePayment, undefined);
  const today = todayIn();

  return (
    <Card label="Reverse payment">
      <h2 className="text-[17px] font-bold">Reverse this payment</h2>
      <p className="mt-1.5 text-[13px] text-muted">
        For a cheque that bounced or a transfer that came back. The entry is reversed and its {made ? "bills are open" : "invoices owe"} again.
      </p>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
        <input type="hidden" name="id" value={id} />
        <div>
          <label className="field-label" htmlFor="date">Date</label>
          <DateInput className="field" id="date" name="date" defaultValue={today} />
        </div>
        <div>
          <label className="field-label" htmlFor="reason">Reason</label>
          <input className="field" id="reason" name="reason" maxLength={500} />
        </div>
        <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Reversing…" : "Reverse payment"}</button>
      </form>
    </Card>
  );
}

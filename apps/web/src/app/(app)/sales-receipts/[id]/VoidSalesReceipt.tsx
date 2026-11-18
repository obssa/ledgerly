"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { voidSalesReceipt } from "../actions";

/** A mistake: the entry is reversed, the goods come back and the number is kept. Enter it again correctly. Needs a reason. */
export function VoidSalesReceipt({ id }: { id: string }) {
  const [state, action, pending] = useActionState(voidSalesReceipt, undefined);

  return (
    <Card label="Void cash sale">
      <h2 className="text-[17px] font-bold">Void this cash sale</h2>
      <p className="mt-1.5 text-[13px] text-muted">For a sale entered by mistake. The entry is reversed, any goods come back into stock and the number is kept.</p>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
        <input type="hidden" name="id" value={id} />
        <div>
          <label className="field-label" htmlFor="reason">Reason</label>
          <input className="field" id="reason" name="reason" minLength={3} maxLength={500} />
        </div>
        <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Voiding…" : "Void cash sale"}</button>
      </form>
    </Card>
  );
}

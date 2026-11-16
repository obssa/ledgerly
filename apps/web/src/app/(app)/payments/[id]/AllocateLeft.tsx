"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { Invoice } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { allocatePaymentMade } from "../../payments-made/actions";
import { allocatePayment } from "../actions";

/** What a payment has not settled yet (a customer's credit, an advance to a supplier), applied to an open invoice or bill. */
export function AllocateLeft({ id, left, documents, made = false }: { id: string; left: string; documents: Invoice[]; made?: boolean }) {
  const [state, action, pending] = useActionState(made ? allocatePaymentMade : allocatePayment, undefined);
  const noun = made ? "bill" : "invoice";
  return (
    <Card label="Not allocated">
      <h2 className="text-[17px] font-bold">Not allocated: <span className="num">{formatAmount(left)}</span></h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {documents.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">No open {noun} in this currency yet. {made ? "The advance waits for the next bill." : "The credit waits for the next invoice."}</p>
      ) : (
        <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
          <input type="hidden" name="id" value={id} />
          <div>
            <label className="field-label" htmlFor="document_id">{made ? "Bill" : "Invoice"}</label>
            <select className="field" id="document_id" name="document_id">
              {documents.map((d) => <option key={d.id} value={d.id}>{d.number} · {made ? "to pay" : "owes"} {formatAmount(d.amount_due)}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="alloc_amount">Amount</label>
            <input className="field num" id="alloc_amount" name="amount" inputMode="decimal" defaultValue={left} />
          </div>
          <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Applying…" : `Apply to the ${noun}`}</button>
        </form>
      )}
    </Card>
  );
}

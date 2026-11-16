"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { Invoice } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { applySupplierCredit } from "../../bills/actions";
import { applyCredit } from "../actions";

/** Credit left on a posted credit note (or supplier credit), applied to another open invoice (or bill) of the same contact and currency. */
export function ApplyCredit({ creditNoteId, left, invoices, bills = false }: { creditNoteId: string; left: string; invoices: Invoice[]; bills?: boolean }) {
  const [state, action, pending] = useActionState(bills ? applySupplierCredit : applyCredit, undefined);
  return (
    <Card label="Apply credit">
      <h2 className="text-[17px] font-bold">Credit left: <span className="num">{formatAmount(left)}</span></h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {invoices.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">{bills ? "No other open bill of this supplier. The credit stays until the next bill." : "No other open invoice of this customer. The credit stays on their account."}</p>
      ) : (
        <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
          <input type="hidden" name="credit_note_id" value={creditNoteId} />
          <div>
            <label className="field-label" htmlFor="invoice_id">{bills ? "Bill" : "Invoice"}</label>
            <select className="field" id="invoice_id" name="invoice_id">
              {invoices.map((i) => <option key={i.id} value={i.id}>{i.number} · {bills ? "to pay" : "owes"} {formatAmount(i.amount_due)}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="amount">Amount</label>
            <input className="field num" id="amount" name="amount" inputMode="decimal" defaultValue={left} />
          </div>
          <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Applying…" : "Apply credit"}</button>
        </form>
      )}
    </Card>
  );
}

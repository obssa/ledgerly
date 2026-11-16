"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import { formatAmount } from "@/lib/format";
import { recordRefund } from "./actions";
import { todayIn } from "@/lib/dates";

const METHODS: Record<string, string> = {
  bank_transfer: "Bank transfer", cash: "Cash", mobile_money: "Mobile money (telebirr, M-PESA …)", cheque: "Cheque", card: "Card", other: "Other",
};

/**
 * Credit given back: to a customer (side "customer", money out) or from a supplier (side "supplier", money in), out of a
 * credit (creditId) or the unused part of a payment (paymentId). The API never refunds more than is left.
 */
export function RefundPanel({ side, contactId, currency, left, creditId, paymentId, accounts }: {
  side: "customer" | "supplier"; contactId: string; currency: string; left: string; creditId?: string; paymentId?: string;
  accounts: { id: string; code: string; name: string }[];
}) {
  const [state, action, pending] = useActionState(recordRefund, undefined);
  const today = todayIn();
  const out = side === "customer";
  return (
    <Card label="Refund">
      <h2 className="text-[17px] font-bold">{out ? "Pay the credit back" : "Record the supplier's refund"}</h2>
      <p className="mt-1.5 text-[13px] text-muted">{out ? "Money goes back to the customer" : "The supplier pays the money back"}: at most <span className="num">{formatAmount(left)}</span>.</p>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {accounts.length === 0 ? (
        <p className="mt-2 text-[13px] text-muted">No bank, cash or mobile-money account in {currency}.</p>
      ) : (
        <form action={action} className="mt-3 flex flex-col gap-3" noValidate>
          <input type="hidden" name="side" value={side} />
          <input type="hidden" name="contact_id" value={contactId} />
          <input type="hidden" name="currency" value={currency} />
          {creditId && <input type="hidden" name="credit_id" value={creditId} />}
          {paymentId && <input type="hidden" name="payment_id" value={paymentId} />}
          <div>
            <label className="field-label" htmlFor="refund_amount">Amount</label>
            <input className={`field num ${state?.fields?.amount ? "!border-bad" : ""}`} id="refund_amount" name="amount" inputMode="decimal" defaultValue={left} />
          </div>
          <div>
            <label className="field-label" htmlFor="refund_account">{out ? "Paid from" : "Received into"}</label>
            <select className="field" id="refund_account" name="account_id">
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="refund_method">Method</label>
            <select className="field" id="refund_method" name="method" defaultValue="bank_transfer">
              {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="refund_date">Date</label>
            <input className="field" id="refund_date" name="payment_date" type="date" defaultValue={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="refund_cheque">Cheque number (cheques only)</label>
            <input className="field" id="refund_cheque" name="cheque_number" maxLength={20} />
          </div>
          <div>
            <label className="field-label" htmlFor="refund_reference">Reference</label>
            <input className="field" id="refund_reference" name="reference" maxLength={100} />
          </div>
          <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Recording…" : out ? "Record refund" : "Record refund received"}</button>
        </form>
      )}
    </Card>
  );
}

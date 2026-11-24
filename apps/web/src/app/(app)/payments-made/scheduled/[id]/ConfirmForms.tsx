"use client";

import { startTransition, useActionState, useState, type FormEvent } from "react";
import { Card } from "@/components/ui";
import type { WithholdingAdvice } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { paymentTotals, suggestedWithholding, withholdingBasis } from "@/lib/payments";
import { cancelScheduledPayment, confirmScheduledPayment } from "../actions";

/**
 * The money went out: confirm it, and the payment made is recorded for the planned bills. The day (today unless it went
 * out earlier), any tax withheld with its receipt number, a bank fee and a cheque number are entered now. What leaves
 * the account is the planned amount less the tax withheld.
 */
export function ConfirmForm({ id, planned, currency, baseCurrency, method, today, bills, advice }: {
  id: string; planned: string; currency: string; baseCurrency: string; method: string; today: string;
  bills: { amount: string; subtotal: string; total: string }[]; advice: WithholdingAdvice | null;
}) {
  const [state, action, pending] = useActionState(confirmScheduledPayment, undefined);
  const basis = withholdingBasis(bills.map((b) => b.amount), bills);
  const suggestion = advice?.applies ? suggestedWithholding(advice.rate, basis, advice.services_above) : "";
  const [withholding, setWithholding] = useState(suggestion);
  const leaves = paymentTotals(planned, "0", [withholding || "0"]).credit;
  const bad = (name: string) => Boolean(state?.fields?.[name]);
  // Submitted by hand, so what was typed stays when the save is refused.
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    startTransition(() => action(data));
  };

  return (
    <Card label="Confirm the payment" className="border-brand/40">
      <h2 className="text-[17px] font-bold">The money went out? Confirm it</h2>
      <p className="mt-1 text-[13.5px] text-muted">The payment is recorded and the bills are marked as paid. Until you confirm, nothing is in the books.</p>
      <form onSubmit={submit} noValidate className="mt-4 grid gap-4">
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        <input type="hidden" name="id" value={id} />
        <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-4">
          <div>
            <label className="field-label" htmlFor="payment_date">Day it went out</label>
            <input className={`field ${bad("payment_date") ? "!border-bad" : ""}`} id="payment_date" name="payment_date" type="date" max={today} defaultValue={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="withholding">Tax withheld</label>
            <input className={`field num ${bad("withholding") ? "!border-bad" : ""}`} id="withholding" name="withholding" inputMode="decimal"
              value={withholding} onChange={(e) => setWithholding(e.target.value)} aria-describedby="withholding-hint"
              disabled={advice !== null && !advice.applies && advice.note.startsWith("Exempt")} />
            <p id="withholding-hint" className="mt-1.5 text-[13px] text-muted">
              {advice?.applies ? `${advice.code}: ${+(Number(advice.rate) * 100).toFixed(2)}% of the price before VAT${suggestion ? `, suggested ${formatAmount(suggestion)}` : ", below the threshold"}` : advice?.note}
            </p>
          </div>
          <div>
            <label className="field-label" htmlFor="withholding_certificate">Withholding receipt number</label>
            <input className={`field ${bad("withholding_certificate") ? "!border-bad" : ""}`} id="withholding_certificate" name="withholding_certificate" maxLength={60} />
          </div>
          <div>
            <label className="field-label" htmlFor="fee">Bank fee</label>
            <input className={`field num ${bad("fee") ? "!border-bad" : ""}`} id="fee" name="fee" inputMode="decimal" />
          </div>
          {method === "cheque" && (
            <div>
              <label className="field-label" htmlFor="cheque_number">Cheque number</label>
              <input className={`field ${bad("cheque_number") ? "!border-bad" : ""}`} id="cheque_number" name="cheque_number" maxLength={20} />
            </div>
          )}
          <div>
            <label className="field-label" htmlFor="reference">Reference (transfer number)</label>
            <input className="field" id="reference" name="reference" maxLength={100} placeholder="Empty: the plan's reference" />
          </div>
          {currency !== baseCurrency && (
            <div>
              <label className="field-label" htmlFor="fx_rate">Rate ({baseCurrency} for 1 {currency})</label>
              <input className={`field num ${bad("fx_rate") ? "!border-bad" : ""}`} id="fx_rate" name="fx_rate" inputMode="decimal" placeholder="The company's rate" />
            </div>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-4">
          <button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending}>{pending ? "Recording…" : "Confirm payment"}</button>
          <p className="text-[13.5px]">Leaves the account: <span className={`num font-bold ${leaves.startsWith("-") ? "text-bad" : ""}`}>{currency} {formatAmount(leaves)}</span></p>
        </div>
      </form>
    </Card>
  );
}

/** It will not be paid this way: cancelled with a reason. Nothing was in the books, so nothing is undone. */
export function CancelForm({ id }: { id: string }) {
  const [state, action, pending] = useActionState(cancelScheduledPayment, undefined);
  const [open, setOpen] = useState(false);
  if (state?.notice) return <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  if (!open) return <button type="button" className="btn" onClick={() => setOpen(true)}>Cancel this payment</button>;

  return (
    <form action={action} className="flex flex-wrap items-end gap-2.5">
      <input type="hidden" name="id" value={id} />
      <div className="min-w-[260px] flex-1">
        <label className="field-label" htmlFor="reason">Why is it cancelled?</label>
        <input className={`field ${state?.fields?.reason ? "!border-bad" : ""}`} id="reason" name="reason" required minLength={3} maxLength={300} placeholder="Paid another way, bill disputed…" />
      </div>
      <button type="submit" className="btn" disabled={pending}>{pending ? "Cancelling…" : "Cancel payment"}</button>
      <button type="button" className="btn" onClick={() => setOpen(false)}>Keep it</button>
      {state?.error && <p role="alert" className="w-full text-[13px] font-semibold text-bad">{state.error}</p>}
    </form>
  );
}

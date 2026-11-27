"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState, useState } from "react";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { cancelPostDatedCheque, presentPostDatedCheque, recordPostDatedCheque } from "./actions";

type Option = { id: string; name: string };

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  if (state?.fields) return <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] text-bad">{Object.values(state.fields).flat().join(" ")}</p>;
  return null;
}

/** A cheque received from a customer, or written to a supplier, dated later than today. */
export function RecordPostDatedCheque({ customers, suppliers, banks, currencies, canReceive, canPay }: {
  customers: Option[]; suppliers: Option[]; banks: { id: string; code: string; name: string }[]; currencies: string[]; canReceive: boolean; canPay: boolean;
}) {
  const [state, action, pending] = useActionState(recordPostDatedCheque, undefined);
  const [direction, setDirection] = useState<"in" | "out">(canReceive ? "in" : "out");
  const people = direction === "in" ? customers : suppliers;
  const today = todayIn();
  return (
    <form action={action} className="grid gap-2.5">
      <Message state={state} />
      <fieldset className="flex flex-wrap gap-4 text-sm">
        <legend className="field-label">The cheque</legend>
        {canReceive && <label className="flex items-center gap-2"><input type="radio" name="direction" value="in" checked={direction === "in"} onChange={() => setDirection("in")} className="accent-brand" /> Received from a customer</label>}
        {canPay && <label className="flex items-center gap-2"><input type="radio" name="direction" value="out" checked={direction === "out"} onChange={() => setDirection("out")} className="accent-brand" /> Written to a supplier</label>}
      </fieldset>
      <div>
        <label className="field-label" htmlFor="pdc_contact">{direction === "in" ? "Customer" : "Supplier"}</label>
        <select className="field" id="pdc_contact" name="contact_id" key={direction}>
          <option value="">Choose…</option>
          {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label" htmlFor="pdc_account">{direction === "in" ? "To be banked in" : "Drawn on"}</label>
        <select className="field" id="pdc_account" name="account_id">
          {banks.map((b) => <option key={b.id} value={b.id}>{b.code} {b.name}</option>)}
        </select>
      </div>
      <div className="flex flex-wrap gap-2">
        <div><label className="field-label" htmlFor="pdc_number">Cheque number</label><input className="field num w-[140px]" id="pdc_number" name="cheque_number" maxLength={20} /></div>
        {direction === "in" && <div className="min-w-[150px] flex-1"><label className="field-label" htmlFor="pdc_bank">Their bank</label><input className="field" id="pdc_bank" name="drawn_on" maxLength={80} /></div>}
      </div>
      <div className="flex flex-wrap gap-2">
        <div><label className="field-label" htmlFor="pdc_handed">{direction === "in" ? "Received on" : "Written on"}</label><DateInput className="field" id="pdc_handed" name="handed_on" defaultValue={today} max={today} /></div>
        <div><label className="field-label" htmlFor="pdc_date">Dated</label><DateInput className="field" id="pdc_date" name="cheque_date" min={today} /></div>
      </div>
      <div className="flex flex-wrap gap-2">
        <div>
          <label className="field-label" htmlFor="pdc_currency">Currency</label>
          <select className="field" id="pdc_currency" name="currency" defaultValue={currencies[0]}>{currencies.map((c) => <option key={c} value={c}>{c}</option>)}</select>
        </div>
        <div><label className="field-label" htmlFor="pdc_amount">Amount</label><input className="field num w-[140px]" id="pdc_amount" name="amount" inputMode="decimal" /></div>
      </div>
      <div><label className="field-label" htmlFor="pdc_note">Note</label><input className="field" id="pdc_note" name="note" maxLength={300} /></div>
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Recording…" : "Record the cheque"}</button></div>
      <p className="text-[13px] text-muted">It is held, not booked, until its date. A cheque dated today or earlier is recorded as a payment received or made.</p>
    </form>
  );
}

/** Present a held cheque on or after its date, or cancel it with a reason. */
export function ChequeStep({ id, due }: { id: string; due: boolean }) {
  const [presented, present, presenting] = useActionState(presentPostDatedCheque, undefined);
  const [cancelled, cancel, cancelling] = useActionState(cancelPostDatedCheque, undefined);
  return (
    <div className="grid gap-1.5">
      <Message state={presented} />
      <Message state={cancelled} />
      {due && (
        <form action={present}><input type="hidden" name="id" value={id} /><button type="submit" className="btn btn-primary !py-1 text-[13px]" disabled={presenting}>{presenting ? "Presenting…" : "Present"}</button></form>
      )}
      <details>
        <summary className="cursor-pointer text-[12px] text-muted">Cancel</summary>
        <form action={cancel} className="mt-1 grid gap-1">
          <input type="hidden" name="id" value={id} />
          <input className="field text-[13px]" name="reason" placeholder="Returned to the customer" maxLength={300} aria-label="Why it is cancelled" />
          <button type="submit" className="btn !py-1 text-[13px]" disabled={cancelling}>Cancel the cheque</button>
        </form>
      </details>
    </div>
  );
}

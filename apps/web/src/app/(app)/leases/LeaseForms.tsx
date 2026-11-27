"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState, useState } from "react";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { addLease, endLease, payLease } from "./actions";

type Choice = { id: string; code: string; name: string };

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

function Pick({ id, name, label, choices, bad }: { id: string; name: string; label: string; choices: Choice[]; bad: boolean }) {
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <select className={`field ${bad ? "!border-bad" : ""}`} id={id} name={name} defaultValue="">
        <option value="" disabled>Choose</option>
        {choices.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}
      </select>
    </div>
  );
}

/** 095: set a lease up: finance (the asset and liability are recognised) or operating (an expense as it is paid). */
export function LeaseForm({ options }: { options: { liability: Choice[]; expense: Choice[]; fixedAsset: Choice[]; accumulated: Choice[]; lessors: { id: string; name: string }[] } }) {
  const [state, action, pending] = useActionState(addLease, undefined);
  const [kind, setKind] = useState<"finance" | "operating">("operating");
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
        <div><label className="field-label" htmlFor="lease-code">Code</label><input className={`field num ${bad("code") ? "!border-bad" : ""}`} id="lease-code" name="code" maxLength={30} placeholder="L-2027-01" /></div>
        <div><label className="field-label" htmlFor="lease-name">What is leased</label><input className={`field ${bad("name") ? "!border-bad" : ""}`} id="lease-name" name="name" maxLength={120} placeholder="Bole Road shop" /></div>
        <div>
          <label className="field-label" htmlFor="lease-lessor">Lessor</label>
          <select className="field" id="lease-lessor" name="lessor_id" defaultValue=""><option value="">—</option>{options.lessors.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</select>
        </div>
        <div>
          <label className="field-label" htmlFor="lease-kind">Kind</label>
          <select className="field" id="lease-kind" name="kind" value={kind} onChange={(e) => setKind(e.target.value === "finance" ? "finance" : "operating")}>
            <option value="operating">Operating (an expense as paid)</option>
            <option value="finance">Finance (the asset passes to you)</option>
          </select>
        </div>
        <div><label className="field-label" htmlFor="lease-start">Starts on</label><DateInput className={`field num ${bad("starts_on") ? "!border-bad" : ""}`} id="lease-start" name="starts_on" defaultValue={todayIn()} /></div>
        <div><label className="field-label" htmlFor="lease-months">Months</label><input className={`field num ${bad("months") ? "!border-bad" : ""}`} id="lease-months" name="months" inputMode="numeric" placeholder="36" /></div>
        <div><label className="field-label" htmlFor="lease-payment">Monthly payment</label><input className={`field num ${bad("payment") ? "!border-bad" : ""}`} id="lease-payment" name="payment" inputMode="decimal" /></div>
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="in_advance" />Paid in advance (at the start of each month)</label>
      {kind === "operating" ? (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3">
          <Pick id="lease-expense" name="expense_account_id" label="Rent or lease expense" choices={options.expense} bad={bad("expense_account_id")} />
        </div>
      ) : (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3">
          <div><label className="field-label" htmlFor="lease-rate">Interest rate (% a year)</label><input className={`field num ${bad("annual_rate") ? "!border-bad" : ""}`} id="lease-rate" name="rate_percent" inputMode="decimal" placeholder="12" /></div>
          <Pick id="lease-liability" name="liability_account_id" label="Lease liability" choices={options.liability} bad={bad("liability_account_id")} />
          <Pick id="lease-interest" name="interest_account_id" label="Interest expense" choices={options.expense} bad={bad("interest_account_id")} />
          <Pick id="lease-asset" name="asset_account_id" label="Asset account" choices={options.fixedAsset} bad={bad("asset_account_id")} />
          <Pick id="lease-acc" name="depreciation_account_id" label="Accumulated depreciation" choices={options.accumulated} bad={bad("depreciation_account_id")} />
          <Pick id="lease-dep" name="depreciation_expense_account_id" label="Depreciation expense" choices={options.expense} bad={bad("depreciation_expense_account_id")} />
          <div><label className="field-label" htmlFor="lease-life">Depreciate over (months)</label><input className="field num" id="lease-life" name="useful_life_months" inputMode="numeric" placeholder="the lease term" /></div>
        </div>
      )}
      <p className="text-[13px] text-muted">
        {kind === "finance"
          ? "The asset and the liability go on the books at the present value of the payments; the asset is registered with your fixed assets and depreciated with them; each payment is split into interest and repayment."
          : "Each payment is posted to the expense account when it is made."}
      </p>
      <button type="submit" className="btn btn-primary self-start" disabled={pending}>{pending ? "Setting up…" : "Set up the lease"}</button>
    </form>
  );
}

/** 095: pay the next payment due, from a bank, wallet or cash account. */
export function PayLease({ id, banks, next }: { id: string; banks: Choice[]; next: string }) {
  const [state, action, pending] = useActionState(payLease, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <p className="text-[13px] text-muted">{next}</p>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
        <div><label className="field-label" htmlFor="pay-date">Paid on</label><DateInput className="field num" id="pay-date" name="date" max={today} defaultValue={today} /></div>
        <div>
          <label className="field-label" htmlFor="pay-bank">Paid from</label>
          <select className="field" id="pay-bank" name="bank_account_id" defaultValue=""><option value="" disabled>Choose</option>{banks.map((b) => <option key={b.id} value={b.id}>{b.code} {b.name}</option>)}</select>
        </div>
      </div>
      <button type="submit" className="btn btn-primary self-start" disabled={pending}>{pending ? "Posting…" : "Post the payment"}</button>
    </form>
  );
}

export function EndLease({ id }: { id: string }) {
  const [state, action, pending] = useActionState(endLease, undefined);
  return (
    <form action={action}>
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <button type="submit" className="btn" disabled={pending}>End the lease</button>
    </form>
  );
}

"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState, useState } from "react";
import type { BankAccount } from "@/lib/api";
import { countTotal, DENOMINATIONS } from "@/lib/cash";
import type { FormState } from "@/lib/forms";
import { countCash, saveBankAccount, transferMoney } from "./actions";
import { todayIn } from "@/lib/dates";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** The float the box is kept at. */
export function FloatForm({ account }: { account: BankAccount }) {
  const [state, action, pending] = useActionState(saveBankAccount, undefined);
  return (
    <form action={action} noValidate className="flex flex-wrap items-end gap-2">
      <div className="w-full"><Message state={state} /></div>
      <input type="hidden" name="id" value={account.id} />
      <div>
        <label className="field-label" htmlFor="float_amount">Float ({account.currency})</label>
        <input className="field num" id="float_amount" name="float_amount" inputMode="decimal" defaultValue={account.float_amount ?? ""} placeholder="Not kept at a float" />
      </div>
      <button type="submit" className="btn" disabled={pending}>Save float</button>
    </form>
  );
}

/** Top the box back up to its float from a bank or wallet: an ordinary transfer, filled in. */
export function TopUpForm({ account, sources }: { account: BankAccount; sources: BankAccount[] }) {
  const [state, action, pending] = useActionState(transferMoney, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="flex flex-wrap items-end gap-2">
      <div className="w-full"><Message state={state} /></div>
      <input type="hidden" name="to_account_id" value={account.id} />
      <div>
        <label className="field-label" htmlFor="topup_from">From</label>
        <select className="field" id="topup_from" name="from_account_id">{sources.map((s) => <option key={s.id} value={s.id}>{`${s.code} ${s.name}`}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="topup_amount">Amount</label>
        <input className="field num" id="topup_amount" name="amount" inputMode="decimal" defaultValue={account.top_up && account.top_up !== "0.00" ? account.top_up : ""} />
      </div>
      <div>
        <label className="field-label" htmlFor="topup_date">Date</label>
        <DateInput className="field" id="topup_date" name="date" defaultValue={today} />
      </div>
      <input type="hidden" name="memo" value={`Top-up of ${account.name}`} />
      <button type="submit" className="btn" disabled={pending}>{pending ? "Posting…" : "Top up"}</button>
    </form>
  );
}

/** Count the box: who counted, the notes and coins (or just the total), and a note. */
export function CashCountForm({ account }: { account: BankAccount }) {
  const [state, action, pending] = useActionState(countCash, undefined);
  const today = todayIn();
  const values = DENOMINATIONS[account.currency] ?? [];
  const [pieces, setPieces] = useState<Record<string, string>>({});
  const total = countTotal(pieces);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="account_id" value={account.id} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3">
        <div>
          <label className="field-label" htmlFor="counted_on">Counted on</label>
          <DateInput className="field" id="counted_on" name="counted_on" max={today} defaultValue={today} />
        </div>
        <div>
          <label className="field-label" htmlFor="counted_by_name">Counted by</label>
          <input className="field" id="counted_by_name" name="counted_by_name" maxLength={120} placeholder="Full name" />
        </div>
        <div>
          <label className="field-label" htmlFor="counted">{values.length > 0 ? "Or the total counted" : "Total counted"}</label>
          <input className="field num" id="counted" name="counted" inputMode="decimal" placeholder={values.length > 0 ? total : "0.00"} />
        </div>
      </div>
      {values.length > 0 && (
        <fieldset className="grid grid-cols-[repeat(auto-fit,minmax(110px,1fr))] gap-2">
          <legend className="field-label">Notes and coins (how many of each)</legend>
          {values.map((v) => (
            <div key={v}>
              <label className="text-[12px] text-muted" htmlFor={`pieces-${v}`}>{v}</label>
              <input className="field num" id={`pieces-${v}`} name={`pieces:${v}`} inputMode="numeric" value={pieces[v] ?? ""} onChange={(e) => setPieces({ ...pieces, [v]: e.target.value })} />
            </div>
          ))}
        </fieldset>
      )}
      {values.length > 0 && <p className="text-[13px]">Notes and coins add up to <span className="num font-semibold">{total}</span>.</p>}
      <div>
        <label className="field-label" htmlFor="count_note">Note</label>
        <input className="field" id="count_note" name="note" maxLength={300} />
      </div>
      <p className="text-[13px] text-muted">Anything different from the books is posted to cash over and short on the day of the count. A count is kept as made: to correct it, count again.</p>
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Recording…" : "Record count"}</button></div>
    </form>
  );
}

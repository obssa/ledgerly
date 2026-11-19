"use client";

import { useActionState, useState } from "react";
import type { PurchaseRequisition } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { formatAmount } from "@/lib/format";
import { decideRequisition, orderRequisition, requestPurchase, withdrawRequisition } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

export function NewRequisition() {
  const [state, action, pending] = useActionState(requestPurchase, undefined);
  const [rows, setRows] = useState(2);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-4">
      <Message state={state} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
        <div className="sm:col-span-2"><label className="field-label" htmlFor="reason">What it is for</label><input className="field" id="reason" name="reason" required minLength={3} maxLength={500} placeholder="For the cafe counter" /></div>
        <div><label className="field-label" htmlFor="needed_by">Needed by (optional)</label><input className="field num" id="needed_by" name="needed_by" type="date" min={today} /></div>
      </div>
      <table className="w-full text-sm">
        <thead><tr className="text-left text-[12px] text-muted"><th className="py-1 pr-2">What</th><th className="py-1 pr-2">How many</th><th className="py-1">About, each (optional)</th></tr></thead>
        <tbody>
          {Array.from({ length: rows }, (_, i) => (
            <tr key={i}>
              <td className="py-1 pr-2"><label className="sr-only" htmlFor={`desc-${i}`}>Item {i + 1}</label><input className="field" id={`desc-${i}`} name={`desc.${i}`} maxLength={500} placeholder={i === 0 ? "Paper cups, box of 1,000" : ""} /></td>
              <td className="py-1 pr-2"><label className="sr-only" htmlFor={`qty-${i}`}>Quantity of item {i + 1}</label><input className="field num w-[90px]" id={`qty-${i}`} name={`qty.${i}`} inputMode="decimal" defaultValue="1" /></td>
              <td className="py-1"><label className="sr-only" htmlFor={`price-${i}`}>Estimated price of item {i + 1}</label><input className="field num w-[120px]" id={`price-${i}`} name={`price.${i}`} inputMode="decimal" /></td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="flex flex-wrap gap-2">
        {rows < 30 && <button type="button" className="btn" onClick={() => setRows((r) => r + 1)}>+ Another line</button>}
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Sending…" : "Ask for it"}</button>
      </div>
    </form>
  );
}

export function DecideRequisition({ id }: { id: string }) {
  const [state, action, pending] = useActionState(decideRequisition, undefined);
  return (
    <form action={action} noValidate className="grid gap-2">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <div><label className="field-label" htmlFor="note">Note (needed to reject)</label><input className="field" id="note" name="note" maxLength={500} /></div>
      <div className="flex gap-2">
        <button type="submit" name="decision" value="approve" className="btn btn-primary flex-1 justify-center" disabled={pending}>Approve</button>
        <button type="submit" name="decision" value="reject" className="btn flex-1 justify-center" disabled={pending}>Reject</button>
      </div>
    </form>
  );
}

export function WithdrawRequisition({ id }: { id: string }) {
  const [state, action, pending] = useActionState(withdrawRequisition, undefined);
  return (
    <form action={action}>
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <button type="submit" className="btn w-full justify-center" disabled={pending}>{pending ? "Withdrawing…" : "Withdraw the request"}</button>
    </form>
  );
}

export function OrderRequisition({ r }: { r: PurchaseRequisition }) {
  const [state, action, pending] = useActionState(orderRequisition, undefined);
  const options = r.options ?? { suppliers: [], accounts: [] };
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={r.id} />
      <input type="hidden" name="lines" value={r.lines.length} />
      <div>
        <label className="field-label" htmlFor="contact_id">Supplier</label>
        <select className="field" id="contact_id" name="contact_id">{options.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="account_id">Bought as</label>
        <select className="field" id="account_id" name="account_id">{options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
      </div>
      {r.lines.map((l, i) => (
        <div key={i}>
          <label className="field-label" htmlFor={`oprice-${i}`}>Price each: {l.description}</label>
          <input className="field num" id={`oprice-${i}`} name={`price.${i}`} inputMode="decimal" placeholder={l.estimated_price ? formatAmount(l.estimated_price) : "0.00"} />
        </div>
      ))}
      <button type="submit" className="btn btn-primary justify-center" disabled={pending || options.suppliers.length === 0}>{pending ? "Ordering…" : "Make the purchase order"}</button>
      <p className="text-[12px] text-muted">A draft purchase order to check and send; nothing posts.</p>
    </form>
  );
}

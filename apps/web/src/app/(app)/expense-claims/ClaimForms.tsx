"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState, useState } from "react";
import { todayIn } from "@/lib/dates";
import { createExpenseClaim, submitExpenseClaim } from "./actions";

/** A new claim: one row per receipt, the amount paid (tax included) and what it was for. */
export function NewClaimForm({ accounts, taxRates }: { accounts: { id: string; code: string; name: string }[]; taxRates: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(createExpenseClaim, undefined);
  const [rows, setRows] = useState(2);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-4">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
        <div><label className="field-label" htmlFor="issue_date">Date</label><DateInput className="field num" id="issue_date" name="issue_date" max={today} defaultValue={today} /></div>
        <div className="sm:col-span-2"><label className="field-label" htmlFor="notes">What it was for (optional)</label><input className="field" id="notes" name="notes" maxLength={2000} placeholder="Visit to Hawassa" /></div>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-[12px] text-muted"><th className="py-1 pr-2">Receipt</th><th className="py-1 pr-2">Amount paid</th><th className="py-1 pr-2">Expense</th><th className="py-1">Tax on it</th></tr></thead>
          <tbody>
            {Array.from({ length: rows }, (_, i) => (
              <tr key={i}>
                <td className="py-1 pr-2"><label className="sr-only" htmlFor={`desc-${i}`}>Receipt {i + 1}</label><input className="field" id={`desc-${i}`} name={`desc.${i}`} maxLength={500} placeholder={i === 0 ? "Taxi to the airport" : ""} /></td>
                <td className="py-1 pr-2"><label className="sr-only" htmlFor={`amount-${i}`}>Amount of receipt {i + 1}</label><input className="field num w-[120px]" id={`amount-${i}`} name={`amount.${i}`} inputMode="decimal" /></td>
                <td className="py-1 pr-2">
                  <label className="sr-only" htmlFor={`account-${i}`}>Expense for receipt {i + 1}</label>
                  <select className="field" id={`account-${i}`} name={`account.${i}`}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
                </td>
                <td className="py-1">
                  <label className="sr-only" htmlFor={`tax-${i}`}>Tax on receipt {i + 1}</label>
                  <select className="field" id={`tax-${i}`} name={`tax.${i}`} defaultValue=""><option value="">None</option>{taxRates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex flex-wrap gap-2">
        {rows < 20 && <button type="button" className="btn" onClick={() => setRows((r) => r + 1)}>+ Another receipt</button>}
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save the claim"}</button>
      </div>
      <p className="text-[13px] text-muted">Saved as a draft: add photos of the receipts on the next page, then send it for approval.</p>
    </form>
  );
}

export function SubmitClaim({ id }: { id: string }) {
  const [state, action, pending] = useActionState(submitExpenseClaim, undefined);
  return (
    <form action={action}>
      <input type="hidden" name="id" value={id} />
      {state?.error && <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <button type="submit" className="btn btn-primary w-full justify-center" disabled={pending}>{pending ? "Sending…" : "Send for approval"}</button>
    </form>
  );
}

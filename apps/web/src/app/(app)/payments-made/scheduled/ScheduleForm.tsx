"use client";

import { Dt } from "@/components/Dt";

import { startTransition, useActionState, useState, type FormEvent } from "react";
import { Card } from "@/components/ui";
import { formatAmount } from "@/lib/format";
import { paymentTotals } from "@/lib/payments";
import { METHOD_LABEL } from "@/lib/scheduledPayments";
import { schedulePayment, updateScheduledPayment } from "./actions";

export interface ScheduleBill { id: string; number: string | null; reference: string | null; due_date: string | null; amount_due: string; currency: string }

/**
 * Plans a payment to one supplier for a later day: tick the bills (all that is due, or type less), pick the day, the
 * account and the method. Nothing goes into the books now; on the day, Evenbooks reminds the person who planned it,
 * and the payment is recorded when someone confirms the money went out.
 */
export function ScheduleForm({ supplier, currency, bills, accounts, today, initial, demo }: {
  supplier: { id: string; name: string };
  currency: string;
  bills: ScheduleBill[];
  accounts: { id: string; code: string; name: string }[];
  today: string;
  /** Editing a plan: its id, values and the bills it has (bill id → amount). */
  initial?: { id: string; pay_on: string; account_id: string; method: string; reference: string | null; note: string | null; bills: Record<string, string> };
  demo?: boolean;
}) {
  const [state, action, pending] = useActionState(initial ? updateScheduledPayment : schedulePayment, undefined);
  const [ticked, setTicked] = useState<boolean[]>(bills.map((b) => (initial ? b.id in initial.bills : false)));
  const [amounts, setAmounts] = useState<string[]>(bills.map((b) => initial?.bills[b.id] ?? b.amount_due));
  const [problem, setProblem] = useState<string | null>(null);
  const firstDue = bills.map((b) => b.due_date).filter((d): d is string => d !== null && d >= today).sort()[0];
  const total = paymentTotals("0", "0", amounts.filter((_, i) => ticked[i])).allocated;
  const bad = (name: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === name || k.startsWith(`${name}.`)));

  // Submitted by hand: what was typed stays when a save is refused, and a plan with no bill is caught first.
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!ticked.some(Boolean)) {
      setProblem("Tick at least one bill to pay.");
      return;
    }
    setProblem(null);
    const data = new FormData(e.currentTarget);
    startTransition(() => action(data));
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {(problem ?? state?.error) && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{problem ?? state?.error}</p>}
      {initial ? <input type="hidden" name="id" value={initial.id} /> : <>
        <input type="hidden" name="contact_id" value={supplier.id} />
        <input type="hidden" name="currency" value={currency} />
      </>}

      <Card className="!p-2" label="Bills to pay">
        {bills.length === 0 ? (
          <p className="p-3 text-sm text-muted">{supplier.name} has no open bills in {currency}. Only bills can be scheduled for payment.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col" className="w-10"><span className="sr-only">Pay</span></th><th scope="col">Bill</th><th scope="col">Supplier invoice no.</th>
                  <th scope="col">Due date</th><th scope="col" className="!text-right">Open balance</th><th scope="col" className="!text-right">Pay on the day</th></tr>
              </thead>
              <tbody>
                {bills.map((b, i) => (
                  <tr key={b.id} className={bad(`bills.${i}`) ? "bg-bad-tint" : ""}>
                    <td><input type="checkbox" className="size-4" name={`bill:${b.id}`} aria-label={`Pay ${b.number ?? b.reference ?? "bill"}`} checked={ticked[i]}
                      onChange={(e) => { setTicked((t) => t.map((v, j) => (j === i ? e.target.checked : v))); setProblem(null); }} /></td>
                    <td className="num font-semibold">{b.number ?? "—"}</td>
                    <td className="num">{b.reference ?? "—"}</td>
                    <td className={`num ${b.due_date !== null && b.due_date < today ? "font-semibold text-bad" : ""}`}><Dt v={b.due_date} /></td>
                    <td className="num text-right">{formatAmount(b.amount_due)}</td>
                    <td className="text-right">
                      <input aria-label={`Amount for ${b.number ?? "bill"}`} inputMode="decimal" className="field num ml-auto w-[130px] text-right disabled:opacity-50"
                        name={`amount:${b.id}`} value={amounts[i]} disabled={!ticked[i]}
                        onChange={(e) => setAmounts((a) => a.map((v, j) => (j === i ? e.target.value : v)))} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 px-3 pb-2 pt-3 text-sm">
          {bills.length > 1 && (
            <button type="button" className="btn" onClick={() => { setTicked(bills.map(() => true)); setAmounts(bills.map((b) => b.amount_due)); setProblem(null); }}>
              All of them, in full
            </button>
          )}
          <dl className="ml-auto flex gap-5">
            <div><dt className="text-muted">To pay on the day ({currency})</dt><dd className="num text-[17px] font-bold">{formatAmount(total)}</dd></div>
          </dl>
        </div>
      </Card>

      <Card label="When and how">
        <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
          <div>
            <label className="field-label" htmlFor="pay_on">Pay on</label>
            <input className={`field ${bad("pay_on") ? "!border-bad" : ""}`} id="pay_on" name="pay_on" type="date" min={today}
              defaultValue={initial?.pay_on ?? firstDue ?? today} aria-describedby="pay_on-hint" />
            <p id="pay_on-hint" className="mt-1.5 text-[13px] text-muted">You get an email that morning.</p>
          </div>
          <div>
            <label className="field-label" htmlFor="account_id">Pay from</label>
            <select className={`field ${bad("account_id") ? "!border-bad" : ""}`} id="account_id" name="account_id" defaultValue={initial?.account_id}>
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="method">Method</label>
            <select className="field" id="method" name="method" defaultValue={initial?.method ?? "bank_transfer"}>
              {Object.entries(METHOD_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="reference">Reference</label>
            <input className="field" id="reference" name="reference" maxLength={100} defaultValue={initial?.reference ?? ""} />
          </div>
          <div className="col-span-full">
            <label className="field-label" htmlFor="note">Note</label>
            <input className="field" id="note" name="note" maxLength={300} defaultValue={initial?.note ?? ""} placeholder="For whoever confirms it, such as who approved it" />
          </div>
        </div>
        <p className="mt-3 text-[13px] text-muted">Nothing goes into the books now. When the money has gone out, open the payment and press Confirm: then it is recorded and the bills are marked as paid. Tax withheld and any bank fee are entered then.</p>
      </Card>

      {!demo && (
        <div>
          <button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending || accounts.length === 0 || bills.length === 0}>
            {pending ? "Saving…" : initial ? "Save changes" : "Schedule payment"}
          </button>
        </div>
      )}
      {accounts.length === 0 && <p className="text-sm text-muted">No bank, cash or mobile-money account in {currency}.</p>}
    </form>
  );
}

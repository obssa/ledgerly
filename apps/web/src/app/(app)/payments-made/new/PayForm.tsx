"use client";
import { DateInput } from "@/components/DateInput";

import { Dt } from "@/components/Dt";

import { startTransition, useActionState, useState, type FormEvent } from "react";
import { Card } from "@/components/ui";
import type { PayOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { allocateOldestFirst, paymentTotals, settleProblem, suggestedWithholding, withholdingBasis } from "@/lib/payments";
import { payBills } from "../actions";
import { todayIn } from "@/lib/dates";

const METHODS: Record<string, string> = {
  bank_transfer: "Bank transfer", cheque: "Cheque", mobile_money: "Mobile money (telebirr, M-PESA …)", cash: "Cash", card: "Card", other: "Other",
};

/**
 * A payment to the chosen supplier, allocated to their open bills (by due date with one click). What it settles is the
 * amount paid plus the tax withheld; the country pack's withholding rule is offered as a suggestion, worked out on the
 * price before VAT. Anything not allocated is an advance to the supplier.
 */
export function PayForm({ options }: { options: PayOptions & { supplier: NonNullable<PayOptions["supplier"]> } }) {
  const [state, action, pending] = useActionState(payBills, undefined);
  const today = todayIn();
  const currency = options.supplier.currency && options.currencies.includes(options.supplier.currency) ? options.supplier.currency : options.baseCurrency;
  const bills = options.bills.filter((b) => b.currency === currency);
  const accounts = options.accounts.filter((a) => (a.currency ?? options.baseCurrency) === currency);
  const advice = options.advice;

  const [amount, setAmount] = useState("");
  const [withholding, setWithholding] = useState("");
  const [method, setMethod] = useState("bank_transfer");
  const [alloc, setAlloc] = useState<string[]>(bills.map(() => ""));
  const totals = paymentTotals(amount, withholding, alloc);
  const [keepAsCredit, setKeepAsCredit] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  // Submitted by hand: the fields keep what was typed when a save is refused, and an empty Settle column is caught first.
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const missing = settleProblem(alloc, bills.length, keepAsCredit, "bill");
    setProblem(missing);
    if (missing) {
      e.currentTarget.querySelector<HTMLInputElement>('input[name^="alloc:"]')?.focus();
      return;
    }
    const data = new FormData(e.currentTarget);
    startTransition(() => action(data));
  };
  const basis = withholdingBasis(alloc, bills);
  const suggestion = advice?.applies ? suggestedWithholding(advice.rate, basis, advice.services_above) : "";
  // Exact decimals: the amount less the limit, positive when over it.
  const overBy = advice?.cash_limit ? paymentTotals(amount, "0", [advice.cash_limit]).credit : "-1";
  const overCash = method === "cash" && currency === options.baseCurrency && !overBy.startsWith("-") && overBy !== "0.00";
  const bad = (name: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === name || k.startsWith(`${name}.`)));

  /** Pays every open bill by due date; with the suggested withholding, what leaves the bank is the rest. */
  const payAll = () => {
    const full = bills.map((b) => b.amount_due);
    setAlloc(full);
    const wht = advice?.applies ? suggestedWithholding(advice.rate, withholdingBasis(full, bills), advice.services_above) : "";
    setWithholding(wht);
    const due = paymentTotals("0", "0", full).allocated;
    setAmount(paymentTotals(due, "0", [wht || "0"]).credit);   // what leaves the bank: the bills less the tax withheld
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {(problem ?? state?.error) && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{problem ?? state?.error}</p>}
      <input type="hidden" name="contact_id" value={options.supplier.id} />
      <input type="hidden" name="currency" value={currency} />

      <Card className="!p-2" label="Bills to pay">
        {bills.length === 0 ? (
          <p className="p-3 text-sm text-muted">No open bills in {currency}. A payment now is an advance to the supplier.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Bill</th><th scope="col">Supplier invoice no.</th><th scope="col">Due date</th><th scope="col" className="!text-right">Open balance</th><th scope="col" className="!text-right">Pay now</th></tr>
              </thead>
              <tbody>
                {bills.map((b, i) => (
                  <tr key={b.id} className={bad(`allocations.${i}`) ? "bg-bad-tint" : ""}>
                    <td className="num font-semibold">{b.number}</td>
                    <td className="num">{b.reference ?? "—"}</td>
                    <td className={`num ${b.due_date !== null && b.due_date < today ? "font-semibold text-bad" : ""}`}><Dt v={b.due_date} /></td>
                    <td className="num text-right">{formatAmount(b.amount_due)}</td>
                    <td className="text-right">
                      <input aria-label={`Pay ${b.number}`} inputMode="decimal" className={`field num ml-auto w-[130px] text-right ${problem ? "!border-bad" : ""}`} name={`alloc:${b.id}`}
                        value={alloc[i]} onChange={(e) => setAlloc((a) => a.map((v, j) => (j === i ? e.target.value : v)))} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 px-3 pb-2 pt-3 text-sm">
          {bills.length > 0 && (
            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn" onClick={payAll}>Pay all, with withholding</button>
              <button type="button" className="btn" onClick={() => setAlloc(allocateOldestFirst(totals.settles, bills.map((b) => b.amount_due)))}>Spread the amount</button>
            </div>
          )}
          {bills.length > 0 && (
            <label className="flex items-center gap-2 text-[13px]">
              <input type="checkbox" name="keep_as_credit" className="size-4" checked={keepAsCredit} onChange={(e) => { setKeepAsCredit(e.target.checked); setProblem(null); }} />
              Pay no bill now: keep it all as an advance to the supplier
            </label>
          )}
          <dl className="ml-auto flex gap-5">
            <div><dt className="text-muted">Settles</dt><dd className="num font-semibold">{formatAmount(totals.settles)}</dd></div>
            <div><dt className="text-muted">Allocated</dt><dd className="num font-semibold">{formatAmount(totals.allocated)}</dd></div>
            <div><dt className="text-muted">Advance</dt><dd className={`num font-semibold ${totals.credit.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(totals.credit)}</dd></div>
          </dl>
        </div>
      </Card>

      <Card label="Payment">
        <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
          <Field name="amount" label={`Amount paid (${currency})`} value={amount} onChange={setAmount} invalid={bad("amount")} hint="What reaches the supplier" />
          <Field name="withholding" label="Tax withheld" value={withholding} onChange={setWithholding} invalid={bad("withholding")}
            hint={advice?.applies
              ? `${advice.code}: ${+(Number(advice.rate) * 100).toFixed(2)}% of the price before VAT${suggestion ? `, suggested ${formatAmount(suggestion)}` : ", below the threshold"}`
              : advice?.note}
            disabled={advice !== null && !advice.applies && advice.note.startsWith("Exempt")} />
          <Field name="withholding_certificate" label="Withholding receipt number" invalid={bad("withholding_certificate")} hint="Given to the supplier" />
          <Field name="fee" label="Bank fee" invalid={bad("fee")} />
          <div>
            <label className="field-label" htmlFor="payment_date">Date</label>
            <DateInput className={`field ${bad("payment_date") ? "!border-bad" : ""}`} id="payment_date" name="payment_date" defaultValue={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="method">Method</label>
            <select className="field" id="method" name="method" value={method} onChange={(e) => setMethod(e.target.value)}>
              {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="account_id">Paid from</label>
            <select className={`field ${bad("account_id") ? "!border-bad" : ""}`} id="account_id" name="account_id">
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
            </select>
          </div>
          {method === "cheque" && <Field name="cheque_number" label="Cheque number" hint="From the cheque book; each number once per account" invalid={bad("cheque_number")} />}
          <Field name="reference" label={method === "cheque" ? "Reference" : "Reference (transfer number)"} />
          {currency !== options.baseCurrency && <Field name="fx_rate" label={`Rate (${options.baseCurrency} for 1 ${currency})`} hint="Empty: the company's rate for the date" invalid={bad("fx_rate")} />}
        </div>
        {advice?.applies && <p className="mt-3 text-[13px] text-muted">{advice.note} Goods above {formatAmount(advice.goods_above ?? "0")}, services above {formatAmount(advice.services_above ?? "0")}.</p>}
        {overCash && <p role="status" className="mt-3 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Cash above {formatAmount(advice!.cash_limit!)} must go through a bank or an authorised electronic channel.</p>}
      </Card>

      {!options.demo && (
        <div><button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending || accounts.length === 0}>{pending ? "Recording…" : "Record payment"}</button></div>
      )}
      {accounts.length === 0 && <p className="text-sm text-muted">No bank, cash or mobile-money account in {currency}.</p>}
    </form>
  );
}

function Field({ name, label, value, onChange, invalid, hint, disabled }: {
  name: string; label: string; value?: string; onChange?: (v: string) => void; invalid?: boolean; hint?: string; disabled?: boolean;
}) {
  const controlled = value !== undefined && onChange !== undefined;
  return (
    <div>
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} disabled={disabled}
        inputMode={["reference", "withholding_certificate"].includes(name) ? undefined : "decimal"}
        {...(controlled ? { value, onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value) } : {})}
        aria-invalid={invalid || undefined} aria-describedby={hint ? `${name}-hint` : undefined} />
      {hint && <p id={`${name}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

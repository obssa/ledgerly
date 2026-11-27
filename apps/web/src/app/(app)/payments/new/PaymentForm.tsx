"use client";

import { startTransition, useActionState, useState, type FormEvent } from "react";
import { Card } from "@/components/ui";
import type { ReceiveOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { allocateOldestFirst, paymentTotals, settleProblem } from "@/lib/payments";
import { receivePayment } from "../actions";
import { todayIn } from "@/lib/dates";

const METHODS: Record<string, string> = {
  bank_transfer: "Bank transfer", cash: "Cash", cheque: "Cheque", mobile_money: "Mobile money (telebirr, M-PESA …)", card: "Card", other: "Other",
};

/**
 * A payment from the chosen customer, allocated to their open invoices (oldest first with one click). What it settles
 * is the amount received plus the tax the customer withheld; anything left stays as the customer's credit.
 */
export function PaymentForm({ options }: { options: ReceiveOptions & { customer: NonNullable<ReceiveOptions["customer"]> } }) {
  const [state, action, pending] = useActionState(receivePayment, undefined);
  const today = todayIn();
  const currency = options.customer.currency && options.currencies.includes(options.customer.currency) ? options.customer.currency : options.baseCurrency;
  const invoices = options.invoices.filter((i) => i.currency === currency);
  const accounts = options.accounts.filter((a) => (a.currency ?? options.baseCurrency) === currency);

  const [amount, setAmount] = useState("");
  const [withholding, setWithholding] = useState("");
  const [alloc, setAlloc] = useState<string[]>(invoices.map(() => ""));
  const totals = paymentTotals(amount, withholding, alloc);
  const [keepAsCredit, setKeepAsCredit] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  // Submitted by hand: the fields keep what was typed when a save is refused, and an empty Settle column is caught first.
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const missing = settleProblem(alloc, invoices.length, keepAsCredit, "invoice");
    setProblem(missing);
    if (missing) {
      e.currentTarget.querySelector<HTMLInputElement>('input[name^="alloc:"]')?.focus();
      return;
    }
    const data = new FormData(e.currentTarget);
    startTransition(() => action(data));
  };
  const bad = (name: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === name || k.startsWith(`${name}.`)));

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {(problem ?? state?.error) && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{problem ?? state?.error}</p>}
      <input type="hidden" name="contact_id" value={options.customer.id} />
      <input type="hidden" name="currency" value={currency} />

      <Card label="Payment">
        <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
          <Field name="amount" label={`Amount received (${currency})`} value={amount} onChange={setAmount} invalid={bad("amount")} />
          <Field name="withholding" label="Tax withheld by the customer" value={withholding} onChange={setWithholding} hint="On their withholding certificate" invalid={bad("withholding")} />
          {withholding.trim() !== "" && <Field name="withholding_certificate" label="Their certificate number" hint="Keep the certificate: it backs the credit against profit tax" invalid={bad("withholding_certificate")} />}
          <Field name="fee" label="Bank fee kept" invalid={bad("fee")} />
          <div>
            <label className="field-label" htmlFor="payment_date">Date</label>
            <input className={`field ${bad("payment_date") ? "!border-bad" : ""}`} id="payment_date" name="payment_date" type="date" defaultValue={today} />
          </div>
          <div>
            <label className="field-label" htmlFor="method">Method</label>
            <select className="field" id="method" name="method" defaultValue="bank_transfer">
              {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="account_id">Received into</label>
            <select className={`field ${bad("account_id") ? "!border-bad" : ""}`} id="account_id" name="account_id">
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
            </select>
          </div>
          <Field name="cheque_number" label="Cheque number (cheques only)" invalid={bad("cheque_number")} />
          <Field name="reference" label="Reference (transfer number)" />
          {currency !== options.baseCurrency && <Field name="fx_rate" label={`Rate (${options.baseCurrency} for 1 ${currency})`} hint="Empty: the company's rate for the date" invalid={bad("fx_rate")} />}
        </div>
      </Card>

      <Card className="!p-2" label="Invoices settled">
        {invoices.length === 0 ? (
          <p className="p-3 text-sm text-muted">No open invoices in {currency}. The whole payment stays as the customer&apos;s credit.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Invoice</th><th scope="col">Date</th><th scope="col">Due date</th><th scope="col" className="!text-right">Open balance</th><th scope="col" className="!text-right">Settle</th></tr>
              </thead>
              <tbody>
                {invoices.map((inv, i) => (
                  <tr key={inv.id} className={bad(`allocations.${i}`) ? "bg-bad-tint" : ""}>
                    <td className="num font-semibold">{inv.number}</td>
                    <td className="num">{inv.issue_date}</td>
                    <td className="num">{inv.due_date ?? "—"}</td>
                    <td className="num text-right">{formatAmount(inv.amount_due)}</td>
                    <td className="text-right">
                      <input aria-label={`Settle ${inv.number}`} inputMode="decimal" className={`field num ml-auto w-[130px] text-right ${problem ? "!border-bad" : ""}`} name={`alloc:${inv.id}`}
                        value={alloc[i]} onChange={(e) => setAlloc((a) => a.map((v, j) => (j === i ? e.target.value : v)))} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 px-3 pb-2 pt-3 text-sm">
          {invoices.length > 0 && (
            <button type="button" className="btn" onClick={() => setAlloc(allocateOldestFirst(totals.settles, invoices.map((i) => i.amount_due)))}>
              Settle oldest first
            </button>
          )}
          {invoices.length > 0 && (
            <label className="flex items-center gap-2 text-[13px]">
              <input type="checkbox" name="keep_as_credit" className="size-4" checked={keepAsCredit} onChange={(e) => { setKeepAsCredit(e.target.checked); setProblem(null); }} />
              Settle nothing now: keep it all as the customer&apos;s credit
            </label>
          )}
          <dl className="ml-auto flex gap-5">
            <div><dt className="text-muted">Settles</dt><dd className="num font-semibold">{formatAmount(totals.settles)}</dd></div>
            <div><dt className="text-muted">Allocated</dt><dd className="num font-semibold">{formatAmount(totals.allocated)}</dd></div>
            <div><dt className="text-muted">Customer credit</dt><dd className={`num font-semibold ${totals.credit.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(totals.credit)}</dd></div>
          </dl>
        </div>
      </Card>

      {!options.demo && (
        <div><button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending || accounts.length === 0}>{pending ? "Recording…" : "Record payment"}</button></div>
      )}
      {accounts.length === 0 && <p className="text-sm text-muted">No bank, cash or mobile-money account in {currency}.</p>}
    </form>
  );
}

function Field({ name, label, value, onChange, invalid, hint }: { name: string; label: string; value?: string; onChange?: (v: string) => void; invalid?: boolean; hint?: string }) {
  const controlled = value !== undefined && onChange !== undefined;
  return (
    <div>
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} inputMode={["reference"].includes(name) ? undefined : "decimal"}
        {...(controlled ? { value, onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value) } : {})}
        aria-invalid={invalid || undefined} aria-describedby={hint ? `${name}-hint` : undefined} />
      {hint && <p id={`${name}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

"use client";

import Link from "next/link";
import { useActionState, useMemo, useState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceOptions } from "@/lib/data";
import { documentAmounts, lineAmounts } from "@/lib/decimal";
import { formatAmount, formatMoney } from "@/lib/format";
import { createInvoice } from "../actions";

/** Kept for callers and tests that pass tax rates on their own. */
export interface TaxRateOption {
  id: string;
  code: string;
  name: string;
  rate: string;
}

interface Line {
  key: number;
  description: string;
  quantity: string;
  unitPrice: string;
  taxRateId: string;
  accountId: string;
}

const isDecimal = (v: string) => /^\d+(\.\d+)?$/.test(v.replace(/,/g, ""));
const plain = (v: string) => v.replace(/,/g, "");
const addDays = (date: string, days: number) => {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
};

/**
 * A new invoice on the company's own customers, income accounts, tax rates and currencies. Totals are previewed with
 * the same exact-decimal rules as the API (src/lib/decimal.ts mirrors TaxCalculator); the API recalculates on save,
 * and its numbers are the ones that post.
 */
export function InvoiceForm({ options }: { options: InvoiceOptions }) {
  const [state, action, pending] = useActionState(createInvoice, undefined);
  const today = new Date().toISOString().slice(0, 10);
  const firstRate = options.taxRates[0]?.id ?? "";
  const firstAccount = options.accounts[0]?.id ?? "";

  const [customerId, setCustomerId] = useState("");
  const [issueDate, setIssueDate] = useState(today);
  const [currency, setCurrency] = useState(options.baseCurrency);
  const [inclusive, setInclusive] = useState(false);
  const [lines, setLines] = useState<Line[]>([{ key: 1, description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);

  const customer = options.customers.find((c) => c.id === customerId);
  const rateOf = (id: string) => options.taxRates.find((t) => t.id === id)?.rate ?? "0";
  const valid = lines.filter((l) => isDecimal(l.quantity) && isDecimal(l.unitPrice));
  const totals = useMemo(
    () => documentAmounts(valid.map((l) => ({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) })), currency, inclusive),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [lines, inclusive, currency],
  );

  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const addLine = () => setLines((ls) => [...ls, { key: Math.max(0, ...ls.map((l) => l.key)) + 1, description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);
  const removeLine = (key: number) => setLines((ls) => (ls.length > 1 ? ls.filter((l) => l.key !== key) : ls));
  const linesJson = JSON.stringify(lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unitPrice, account_id: l.accountId, tax_rate_id: l.taxRateId || null })));
  const lineError = (i: number) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k.startsWith(`lines.${i}.`)));
  const bad = (name: string) => Boolean(state?.fields?.[name]);

  if (options.customers.length === 0) {
    return (
      <Card label="No customers yet">
        <p className="text-sm">Add a customer first. <Link href="/customers/new" className="font-bold text-brand">New customer</Link></p>
      </Card>
    );
  }

  return (
    <form action={action} noValidate className="flex flex-wrap items-start gap-4">
      <input type="hidden" name="lines" value={linesJson} />
      <div className="flex min-w-0 flex-[2_1_560px] flex-col gap-4">
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        <Card label="Customer and terms">
          <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            <div className="sm:col-span-2">
              <label className="field-label" htmlFor="contact_id">Customer</label>
              <select className={`field ${bad("contact_id") ? "!border-bad" : ""}`} id="contact_id" name="contact_id" value={customerId}
                onChange={(e) => {
                  setCustomerId(e.target.value);
                  const c = options.customers.find((x) => x.id === e.target.value);
                  if (c?.currency && options.currencies.includes(c.currency)) setCurrency(c.currency);
                }}>
                <option value="">Choose a customer</option>
                {options.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {customer && <p className="mt-1.5 text-[13px] text-muted">{customer.tax_id ? <>TIN <span className="num">{customer.tax_id}</span> · </> : "No TIN · "}Terms {customer.payment_terms_days} days</p>}
            </div>
            {options.manualNumbers && <Field name="number" label="Number (from your invoice book)" invalid={bad("number")} />}
            <div>
              <label className="field-label" htmlFor="issue_date">Issue date</label>
              <input className={`field ${bad("issue_date") ? "!border-bad" : ""}`} id="issue_date" name="issue_date" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />
            </div>
            <Field key={`${customerId}-${issueDate}`} name="due_date" label="Due date" type="date" defaultValue={customer ? addDays(issueDate, customer.payment_terms_days) : ""} invalid={bad("due_date")} />
            <div>
              <label className="field-label" htmlFor="currency">Currency</label>
              <select className="field" id="currency" name="currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                {options.currencies.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            {currency !== options.baseCurrency && (
              <Field name="fx_rate" label={`Rate (${options.baseCurrency} for 1 ${currency})`} hint="Empty: the company's rate for the issue date" invalid={bad("fx_rate")} />
            )}
            <div className="flex items-end">
              <label className="flex min-h-[42px] items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="prices_include_tax" checked={inclusive} onChange={(e) => setInclusive(e.target.checked)} className="size-[18px] accent-brand" />
                Prices include VAT
              </label>
            </div>
          </div>
        </Card>

        <Card className="!p-2" label="Line items">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Tax</th><th scope="col">Income account</th><th scope="col" className="!text-right">Amount</th><th scope="col"><span className="sr-only">Remove</span></th></tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const ok = isDecimal(l.quantity) && isDecimal(l.unitPrice);
                  const amt = ok ? lineAmounts({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) }, currency, inclusive) : null;
                  return (
                    <tr key={l.key} className={lineError(i) ? "bg-bad-tint" : ""}>
                      <td><input aria-label="Description" className="field min-w-[180px]" value={l.description} onChange={(e) => update(l.key, { description: e.target.value })} /></td>
                      <td><input aria-label="Quantity" inputMode="decimal" className="field num w-[72px] text-right" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} /></td>
                      <td><input aria-label="Unit price" inputMode="decimal" className="field num w-[110px] text-right" value={l.unitPrice} onChange={(e) => update(l.key, { unitPrice: e.target.value })} /></td>
                      <td>
                        <select aria-label="Tax" className="field w-[130px]" value={l.taxRateId} onChange={(e) => update(l.key, { taxRateId: e.target.value })}>
                          <option value="">No tax</option>
                          {options.taxRates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                      </td>
                      <td>
                        <select aria-label="Income account" className="field w-[160px]" value={l.accountId} onChange={(e) => update(l.key, { accountId: e.target.value })}>
                          {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                        </select>
                      </td>
                      <td className="num text-right font-semibold">{amt ? formatAmount(amt.subtotal, currency) : <span className="text-muted">—</span>}</td>
                      <td className="!pl-0"><button type="button" className="btn !px-3" aria-label={`Remove line ${i + 1}`} onClick={() => removeLine(l.key)}>×</button></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap justify-between gap-4 px-3 pb-2 pt-4">
            <button type="button" className="btn self-start" onClick={addLine}>+ Add line</button>
            <dl className="flex-[0_1_320px] text-sm">
              <div className="flex justify-between py-1.5"><dt>Subtotal</dt><dd className="num">{formatAmount(totals.subtotal, currency)}</dd></div>
              <div className="flex justify-between py-1.5"><dt>Tax</dt><dd className="num">{formatAmount(totals.tax, currency)}</dd></div>
              <div className="mt-1.5 flex justify-between border-t-2 border-ink pt-3 text-lg font-extrabold"><dt>Total</dt><dd className="num font-semibold">{formatMoney(totals.total, currency)}</dd></div>
            </dl>
          </div>
        </Card>
        <Card label="Notes">
          <label className="field-label" htmlFor="notes">Notes for the customer (optional)</label>
          <textarea className="field min-h-[72px]" id="notes" name="notes" maxLength={2000} />
        </Card>
      </div>

      <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
        <Card label="Posting preview">
          <h2 className="text-[17px] font-bold">On approval this posts</h2>
          <dl className="num mt-2 text-[13px]">
            <div className="flex justify-between py-1"><dt>Dr Accounts receivable</dt><dd>{formatAmount(totals.total, currency)}</dd></div>
            <div className="flex justify-between py-1"><dt>Cr Income</dt><dd>{formatAmount(totals.subtotal, currency)}</dd></div>
            <div className="flex justify-between py-1"><dt>Cr Tax payable</dt><dd>{formatAmount(totals.tax, currency)}</dd></div>
          </dl>
          <p className="mt-3 text-[13px] text-muted">
            {options.manualNumbers ? "The number is the one you type." : "The invoice gets its number when it is posted."} Where e-invoicing applies,
            posting also sends it to the tax authority.
          </p>
        </Card>
        {options.demo ? (
          <p className="text-sm text-muted">Saving is not available in the demo.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            <button type="submit" name="intent" value="draft" className="btn" disabled={pending}>Save draft</button>
            <button type="submit" name="intent" value="approve" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save and approve"}</button>
          </div>
        )}
      </aside>
    </form>
  );
}

function Field({ name, label, type = "text", defaultValue = "", invalid, hint }: { name: string; label: string; type?: string; defaultValue?: string; invalid?: boolean; hint?: string }) {
  return (
    <div>
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} type={type} defaultValue={defaultValue} aria-invalid={invalid || undefined}
        aria-describedby={hint ? `${name}-hint` : undefined} />
      {hint && <p id={`${name}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

"use client";

import Link from "next/link";
import { useActionState, useMemo, useState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceDetail } from "@/lib/api";
import type { InvoiceOptions } from "@/lib/data";
import { documentAmounts, lineAmounts } from "@/lib/decimal";
import { formatAmount, formatMoney } from "@/lib/format";
import { createBill } from "../../bills/actions";
import { createExpense } from "../../expenses/actions";
import { createInvoice } from "../actions";
import { saveQuote } from "../../quotes/actions";
import { todayIn } from "@/lib/dates";

/** The words and postings that differ between a sales invoice and a supplier's bill; the form is the same. */
const KIND = {
  invoice: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Number (from your invoice book)",
    notes: "Notes for the customer (optional)", noun: "invoice", posting: [["Dr Accounts receivable", "total"], ["Cr Income", "subtotal"], ["Cr Tax payable", "tax"]],
  },
  bill: {
    party: "Supplier", who: "supplier", parties: "suppliers", newParty: "/suppliers/new", account: "Expense account", numberLabel: "Our number",
    notes: "Notes (optional)", noun: "bill", posting: [["Dr Expense or asset", "subtotal"], ["Dr VAT receivable", "tax"], ["Cr Accounts payable", "total"]],
  },
  expense: {
    party: "Paid to", who: "supplier", parties: "suppliers", newParty: "/suppliers/new", account: "Expense account", numberLabel: "Our number",
    notes: "Notes (optional)", noun: "expense", posting: [["Dr Expense or asset", "subtotal"], ["Dr VAT receivable", "tax"], ["Cr Paid from", "total"]],
  },
  // Quotes and sales orders post nothing (056): no posting preview.
  quote: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Number (from your quote book)",
    notes: "Notes for the customer (optional)", noun: "quote", posting: [],
  },
  sales_order: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Order number",
    notes: "Notes for the customer (optional)", noun: "sales order", posting: [],
  },
} as const;

/** What the form needs from a saved document to edit it: an invoice draft, or a quote or sales order. */
type Draft = Pick<InvoiceDetail, "id" | "contact" | "issue_date" | "due_date" | "currency" | "fx_rate" | "prices_include_tax" | "salesperson_id"
  | "warehouse_id" | "number" | "notes" | "lines" | "lock_version"> & { status: string };

const METHODS: Record<string, string> = {
  cash: "Cash", card: "Card", bank_transfer: "Bank transfer", mobile_money: "Mobile money (telebirr, M-PESA …)", cheque: "Cheque", other: "Other",
};

/** Kept for callers and tests that pass tax rates on their own. */
export interface TaxRateOption {
  id: string;
  code: string;
  name: string;
  rate: string;
}

interface Line {
  key: number;
  itemId: string;
  description: string;
  quantity: string;
  unitPrice: string;
  taxRateId: string;
  accountId: string;
  projectId?: string;
  /** The sales order line this invoice line delivers (067): kept through edits. */
  sourceLineId?: string;
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
export function InvoiceForm({ options, kind = "invoice", draft }: { options: InvoiceOptions; kind?: keyof typeof KIND; draft?: Draft }) {
  const words = KIND[kind];
  const quote = kind === "quote" || kind === "sales_order";
  // A quote already sent, or an order already confirmed, is revised: its earlier version is kept, with what changed.
  const revision = quote && draft !== undefined && draft.status !== "draft";
  const [state, action, pending] = useActionState(kind === "bill" ? createBill : kind === "expense" ? createExpense : quote ? saveQuote : createInvoice, undefined);
  const today = todayIn();
  const firstRate = options.taxRates[0]?.id ?? "";
  const firstAccount = options.accounts[0]?.id ?? "";

  const [customerId, setCustomerId] = useState(draft?.contact.id ?? "");
  const [issueDate, setIssueDate] = useState(draft?.issue_date ?? today);
  const [currency, setCurrency] = useState(draft?.currency ?? options.baseCurrency);
  const [inclusive, setInclusive] = useState(Boolean(draft?.prices_include_tax));
  const projects = options.projects ?? [];
  const [lines, setLines] = useState<Line[]>(draft && draft.lines.length > 0
    ? draft.lines.map((l, i) => ({ key: i + 1, itemId: l.item_id ?? "", description: l.description, quantity: l.quantity, unitPrice: l.unit_price,
        taxRateId: l.tax_rate_id ?? "", accountId: l.account_id, projectId: l.project_id ?? "", sourceLineId: l.source_line_id ?? undefined }))
    : [{ key: 1, itemId: "", description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);
  const items = options.items ?? [];
  const stockLine = lines.some((l) => items.find((i) => i.id === l.itemId)?.type === "inventory");
  /** Picking an item fills the line from it; the person can still change what was filled. */
  const pickItem = (key: number, itemId: string) => {
    const item = items.find((i) => i.id === itemId);
    setLines((ls) => ls.map((l) => (l.key !== key ? l : {
      ...l, itemId,
      ...(item ? { description: item.name, unitPrice: item.price ?? l.unitPrice, accountId: item.account_id && options.accounts.some((a) => a.id === item.account_id) ? item.account_id : l.accountId } : {}),
    })));
  };

  const customer = options.customers.find((c) => c.id === customerId);
  const rateOf = (id: string) => options.taxRates.find((t) => t.id === id)?.rate ?? "0";
  const valid = lines.filter((l) => isDecimal(l.quantity) && isDecimal(l.unitPrice));
  const totals = useMemo(
    () => documentAmounts(valid.map((l) => ({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) })), currency, inclusive),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [lines, inclusive, currency],
  );

  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const addLine = () => setLines((ls) => [...ls, { key: Math.max(0, ...ls.map((l) => l.key)) + 1, itemId: "", description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);
  const removeLine = (key: number) => setLines((ls) => (ls.length > 1 ? ls.filter((l) => l.key !== key) : ls));
  const linesJson = JSON.stringify(lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unitPrice, account_id: l.accountId, tax_rate_id: l.taxRateId || null, item_id: l.itemId || null, project_id: l.projectId || null, ...(l.sourceLineId ? { source_line_id: l.sourceLineId } : {}) })));
  const lineError = (i: number) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k.startsWith(`lines.${i}.`)));
  const bad = (name: string) => Boolean(state?.fields?.[name]);

  if (options.customers.length === 0) {
    return (
      <Card label={`No ${words.parties} yet`}>
        <p className="text-sm">Add a {words.who} first. <Link href={words.newParty} className="font-bold text-brand">New {words.who}</Link></p>
      </Card>
    );
  }

  return (
    <form action={action} noValidate className="flex flex-wrap items-start gap-4">
      <input type="hidden" name="lines" value={linesJson} />
      {draft && <input type="hidden" name="id" value={draft.id} />}
      {draft?.lock_version && <input type="hidden" name="lock_version" value={String(draft.lock_version)} />}
      {quote && <input type="hidden" name="doc_kind" value={kind} />}
      <div className="flex min-w-0 flex-[2_1_560px] flex-col gap-4">
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        <Card label={`${words.party} and terms`}>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            <div className="sm:col-span-2">
              <label className="field-label" htmlFor="contact_id">{words.party}</label>
              <select className={`field ${bad("contact_id") ? "!border-bad" : ""}`} id="contact_id" name="contact_id" value={customerId}
                onChange={(e) => {
                  setCustomerId(e.target.value);
                  const c = options.customers.find((x) => x.id === e.target.value);
                  if (c?.currency && options.currencies.includes(c.currency)) setCurrency(c.currency);
                  // A supplier's usual expense account goes on the lines that still have the first account.
                  const usual = c?.default_account_id;
                  if (usual && options.accounts.some((a) => a.id === usual)) {
                    setLines((ls) => ls.map((l) => (l.accountId === firstAccount ? { ...l, accountId: usual } : l)));
                  }
                }}>
                <option value="">Choose a {words.who}</option>
                {options.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {customer && <p className="mt-1.5 text-[13px] text-muted">{customer.tax_id ? <>TIN <span className="num">{customer.tax_id}</span> · </> : "No TIN · "}Terms {customer.payment_terms_days} days</p>}
            </div>
            {kind === "bill" && <Field name="reference" label="Supplier's invoice number" hint="As printed on their invoice" invalid={bad("reference")} />}
            {kind === "expense" && (
              <>
                <Field name="reference" label="Receipt number" hint="As printed on the receipt" invalid={bad("reference")} />
                <div>
                  <label className="field-label" htmlFor="paid_from_account_id">Paid from</label>
                  <select className={`field ${bad("paid_from_account_id") ? "!border-bad" : ""}`} id="paid_from_account_id" name="paid_from_account_id">
                    {(options.moneyAccounts ?? []).filter((a) => (a.currency ?? options.baseCurrency) === currency).map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="field-label" htmlFor="payment_method">Paid by</label>
                  <select className="field" id="payment_method" name="payment_method" defaultValue="cash">
                    {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                  </select>
                </div>
              </>
            )}
            {options.manualNumbers && !revision && <Field name="number" label={words.numberLabel} defaultValue={draft?.number ?? ""} invalid={bad("number")} />}
            <div>
              <label className="field-label" htmlFor="issue_date">Issue date</label>
              <input className={`field ${bad("issue_date") ? "!border-bad" : ""}`} id="issue_date" name="issue_date" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />
            </div>
            {kind !== "expense" && (
              <Field key={`${customerId}-${issueDate}`} name="due_date" label={kind === "quote" ? "Valid until" : kind === "sales_order" ? "Deliver by" : "Due date"} type="date"
                defaultValue={draft?.due_date && customerId === draft.contact.id && issueDate === draft.issue_date ? draft.due_date
                  : kind === "quote" ? addDays(issueDate, 30) : kind === "sales_order" ? "" : customer ? addDays(issueDate, customer.payment_terms_days) : ""} invalid={bad("due_date")} />
            )}
            <div>
              <label className="field-label" htmlFor="currency">Currency</label>
              <select className="field" id="currency" name="currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                {options.currencies.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            {(kind === "invoice" || quote) && (options.people ?? []).length > 0 && (
              <div>
                <label className="field-label" htmlFor="salesperson_id">Salesperson</label>
                <select className={`field ${bad("salesperson_id") ? "!border-bad" : ""}`} id="salesperson_id" name="salesperson_id" defaultValue={draft?.salesperson_id ?? ""}>
                  <option value="">None</option>
                  {(options.people ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
            )}
            {stockLine && (
              <div>
                <label className="field-label" htmlFor="warehouse_id">{kind === "bill" ? "Goods arrive in" : "Goods leave from"}</label>
                <select className={`field ${bad("warehouse_id") ? "!border-bad" : ""}`} id="warehouse_id" name="warehouse_id" defaultValue={draft?.warehouse_id ?? undefined}>
                  {(options.warehouses ?? []).map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
              </div>
            )}
            {currency !== options.baseCurrency && (
              <Field name="fx_rate" defaultValue={draft && currency === draft.currency && draft.currency !== options.baseCurrency ? draft.fx_rate : ""} label={`Rate (${options.baseCurrency} for 1 ${currency})`} hint="Empty: the company's rate for the issue date" invalid={bad("fx_rate")} />
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
                <tr>{items.length > 0 && kind !== "expense" && <th scope="col">Item</th>}<th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Tax</th><th scope="col">{words.account}</th>{projects.length > 0 && <th scope="col">Project</th>}<th scope="col" className="!text-right">Amount</th><th scope="col"><span className="sr-only">Remove</span></th></tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const ok = isDecimal(l.quantity) && isDecimal(l.unitPrice);
                  const amt = ok ? lineAmounts({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) }, currency, inclusive) : null;
                  return (
                    <tr key={l.key} className={lineError(i) ? "bg-bad-tint" : ""}>
                      {items.length > 0 && kind !== "expense" && (
                        <td>
                          <select aria-label="Item" className="field w-[150px]" value={l.itemId} onChange={(e) => pickItem(l.key, e.target.value)}>
                            <option value="">—</option>
                            {items.map((it) => <option key={it.id} value={it.id}>{it.name}{it.type === "inventory" ? " (stock)" : ""}</option>)}
                          </select>
                        </td>
                      )}
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
                        <select aria-label={words.account} className="field w-[160px]" value={l.accountId} onChange={(e) => update(l.key, { accountId: e.target.value })}>
                          {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                        </select>
                      </td>
                      {projects.length > 0 && (
                        <td>
                          <select aria-label="Project" className="field w-[150px]" value={l.projectId ?? ""} onChange={(e) => update(l.key, { projectId: e.target.value })}>
                            <option value="">—</option>
                            {projects.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}
                          </select>
                        </td>
                      )}
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
          <label className="field-label" htmlFor="notes">{words.notes}</label>
          <textarea className="field min-h-[72px]" id="notes" name="notes" maxLength={2000} defaultValue={draft?.notes ?? ""} />
        </Card>
      </div>

      <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
        {quote ? (
          <Card label="What happens next">
            <h2 className="text-[17px] font-bold">{kind === "quote" ? "A quote posts nothing" : "An order posts nothing"}</h2>
            <p className="mt-1.5 text-[13px] text-muted">
              {kind === "quote"
                ? "Send it to the customer (it is numbered then). When they accept, turn it into a sales order or an invoice. Unanswered after its date, it expires."
                : "Confirm it (it is numbered then), and turn it into an invoice when the goods or the work are delivered."}
            </p>
            {revision && (
              <div className="mt-3">
                <label className="field-label" htmlFor="reason">What changed (kept with the earlier version)</label>
                <input className="field" id="reason" name="reason" maxLength={500} placeholder="Customer asked for 12 bags" />
              </div>
            )}
          </Card>
        ) : (
        <Card label="Posting preview">
          <h2 className="text-[17px] font-bold">On approval this posts</h2>
          <dl className="num mt-2 text-[13px]">
            {words.posting.map(([label, amount]) => (
              <div key={label} className="flex justify-between py-1"><dt>{label}</dt><dd>{formatAmount(totals[amount], currency)}</dd></div>
            ))}
          </dl>
          <p className="mt-3 text-[13px] text-muted">
            {options.manualNumbers ? "The number is the one you type." : `The ${words.noun} gets its number when it is posted.`}{" "}
            {kind === "invoice"
              ? "Where e-invoicing applies, posting also sends it to the tax authority."
              : "VAT is reclaimable only when the company is VAT-registered; otherwise it is part of the cost."}
          </p>
        </Card>
        )}
        {options.demo ? (
          <p className="text-sm text-muted">Saving is not available in the demo.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {kind === "expense" ? (
              <button type="submit" className="btn btn-primary" disabled={pending || (options.moneyAccounts ?? []).length === 0}>{pending ? "Recording…" : "Record expense"}</button>
            ) : revision ? (
              <button type="submit" name="intent" value="draft" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save the revision"}</button>
            ) : quote ? (
              <>
                <button type="submit" name="intent" value="draft" className="btn" disabled={pending}>Save draft</button>
                <button type="submit" name="intent" value={kind === "quote" ? "send" : "accept"} className="btn btn-primary" disabled={pending}>
                  {pending ? "Saving…" : kind === "quote" ? "Save and mark as sent" : "Save and confirm"}
                </button>
              </>
            ) : (
              <>
                <button type="submit" name="intent" value="draft" className="btn" disabled={pending}>Save draft</button>
                <button type="submit" name="intent" value="approve" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save and approve"}</button>
              </>
            )}
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

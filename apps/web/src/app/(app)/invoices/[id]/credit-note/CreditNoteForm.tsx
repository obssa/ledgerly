"use client";

import { useActionState, useMemo, useState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceDetail } from "@/lib/api";
import { documentAmounts } from "@/lib/decimal";
import { formatAmount, formatMoney } from "@/lib/format";
import { createCreditNote } from "../../actions";

interface Line { key: number; description: string; quantity: string; unitPrice: string; taxRateId: string; accountId: string }

const isDecimal = (v: string) => /^\d+(\.\d+)?$/.test(v.replace(/,/g, ""));

/**
 * A credit note starts as a copy of the invoice's lines: keep the items returned (or the price taken off), change the
 * quantities, remove the rest. The customer, currency and rate are the invoice's; the API never lets the credit notes
 * of an invoice add up to more than the invoice.
 */
export function CreditNoteForm({ invoice, taxRates }: { invoice: InvoiceDetail; taxRates: { id: string; name: string; rate: string }[] }) {
  const [state, action, pending] = useActionState(createCreditNote, undefined);
  const today = new Date().toISOString().slice(0, 10);
  const [lines, setLines] = useState<Line[]>(invoice.lines.map((l, i) => ({
    key: i + 1, description: l.description, quantity: l.quantity, unitPrice: l.unit_price, taxRateId: l.tax_rate_id ?? "", accountId: l.account_id,
  })));
  const rateOf = (id: string) => taxRates.find((t) => t.id === id)?.rate ?? "0";
  const valid = lines.filter((l) => isDecimal(l.quantity) && isDecimal(l.unitPrice));
  const totals = useMemo(
    () => documentAmounts(valid.map((l) => ({ quantity: l.quantity.replace(/,/g, ""), unitPrice: l.unitPrice.replace(/,/g, ""), taxRate: rateOf(l.taxRateId) })), invoice.currency, false),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [lines],
  );
  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const json = JSON.stringify(lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unitPrice, account_id: l.accountId, tax_rate_id: l.taxRateId || null })));

  return (
    <form action={action} noValidate className="flex flex-col gap-4">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      <input type="hidden" name="invoice_id" value={invoice.id} />
      <input type="hidden" name="lines" value={json} />
      <Card label="Credit note">
        <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
          <div>
            <label className="field-label" htmlFor="issue_date">Date</label>
            <input className="field" id="issue_date" name="issue_date" type="date" defaultValue={today} min={invoice.issue_date} />
          </div>
          <div className="sm:col-span-2">
            <label className="field-label" htmlFor="notes">Reason (printed on the credit note)</label>
            <input className={`field ${state?.fields?.notes ? "!border-bad" : ""}`} id="notes" name="notes" maxLength={2000} placeholder="For example: two bags returned, damaged in transit" />
          </div>
        </div>
      </Card>
      <Card className="!p-2" label="Lines credited">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col"><span className="sr-only">Remove</span></th></tr></thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={l.key}>
                  <td><input aria-label="Description" className="field min-w-[200px]" value={l.description} onChange={(e) => update(l.key, { description: e.target.value })} /></td>
                  <td><input aria-label="Quantity" inputMode="decimal" className="field num w-[80px] text-right" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} /></td>
                  <td><input aria-label="Unit price" inputMode="decimal" className="field num w-[120px] text-right" value={l.unitPrice} onChange={(e) => update(l.key, { unitPrice: e.target.value })} /></td>
                  <td className="!pl-0">
                    <button type="button" className="btn !px-3" aria-label={`Remove line ${i + 1}`} onClick={() => setLines((ls) => (ls.length > 1 ? ls.filter((x) => x.key !== l.key) : ls))}>×</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="ml-auto max-w-[320px] px-3 pb-2 pt-3 text-sm">
          <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(totals.subtotal)}</dd></div>
          <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(totals.tax)}</dd></div>
          <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 font-extrabold"><dt>Credit</dt><dd className="num">{formatMoney(totals.total, invoice.currency)}</dd></div>
        </dl>
      </Card>
      <div><button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending}>{pending ? "Saving…" : "Save credit note"}</button></div>
    </form>
  );
}

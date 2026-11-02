"use client";

import { useMemo, useState } from "react";
import { Card, Pill } from "@/components/ui";
import { documentAmounts, lineAmounts } from "@/lib/decimal";
import { formatAmount, formatMoney } from "@/lib/format";

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
}

const isDecimal = (v: string) => /^\d+(\.\d+)?$/.test(v);

/**
 * Totals are previewed with the same exact-decimal rules as the API (src/lib/decimal.ts mirrors
 * TaxCalculator), but the API recalculates on save: the server's numbers are the ones that post.
 */
export function InvoiceForm({ taxRates, currency = "ETB" }: { taxRates: TaxRateOption[]; currency?: string }) {
  const [lines, setLines] = useState<Line[]>([
    { key: 1, description: "Roasted coffee 1 kg · Yirgacheffe", quantity: "240", unitPrice: "1150", taxRateId: taxRates[0]?.id ?? "" },
    { key: 2, description: "Roasted coffee 1 kg · Sidamo", quantity: "160", unitPrice: "980", taxRateId: taxRates[0]?.id ?? "" },
    { key: 3, description: "Delivery · Addis Ababa", quantity: "1", unitPrice: "3500", taxRateId: taxRates[0]?.id ?? "" },
  ]);
  const [inclusive, setInclusive] = useState(false);

  const rateOf = (id: string) => taxRates.find((t) => t.id === id)?.rate ?? "0";
  const valid = lines.filter((l) => isDecimal(l.quantity) && isDecimal(l.unitPrice));
  const totals = useMemo(
    () => documentAmounts(valid.map((l) => ({ quantity: l.quantity, unitPrice: l.unitPrice, taxRate: rateOf(l.taxRateId) })), currency, inclusive),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [lines, inclusive, currency],
  );

  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const addLine = () => setLines((ls) => [...ls, { key: Math.max(0, ...ls.map((l) => l.key)) + 1, description: "", quantity: "1", unitPrice: "0", taxRateId: taxRates[0]?.id ?? "" }]);
  const removeLine = (key: number) => setLines((ls) => ls.filter((l) => l.key !== key));

  return (
    <div className="flex flex-wrap items-start gap-4">
      <div className="flex min-w-0 flex-[2_1_560px] flex-col gap-4">
        <Card label="Customer and terms">
          <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            <div className="sm:col-span-2">
              <label className="field-label" htmlFor="customer">Customer</label>
              <input className="field" id="customer" defaultValue="Sheger Grand Hotel" />
              <p className="mt-1.5 text-[13px] text-muted">TIN <span className="num">0012345678</span> · VAT registered · <span className="font-bold text-good">TIN active</span></p>
            </div>
            <Field id="issue" label="Issue date" defaultValue="2026-11-02" type="date" />
            <Field id="due" label="Due date" defaultValue="2026-12-02" type="date" />
            <Field id="currency" label="Currency" defaultValue={currency} />
            <div className="flex items-end">
              <label className="flex min-h-[42px] items-center gap-2 text-sm font-semibold">
                <input type="checkbox" checked={inclusive} onChange={(e) => setInclusive(e.target.checked)} className="size-[18px] accent-brand" />
                Prices include VAT
              </label>
            </div>
          </div>
        </Card>

        <Card className="!p-2" label="Line items">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Tax</th><th scope="col" className="!text-right">Amount</th><th scope="col"><span className="sr-only">Remove</span></th></tr>
              </thead>
              <tbody>
                {lines.map((l) => {
                  const ok = isDecimal(l.quantity) && isDecimal(l.unitPrice);
                  const amt = ok ? lineAmounts({ quantity: l.quantity, unitPrice: l.unitPrice, taxRate: rateOf(l.taxRateId) }, currency, inclusive) : null;
                  return (
                    <tr key={l.key}>
                      <td><input aria-label="Description" className="field min-w-[180px]" value={l.description} onChange={(e) => update(l.key, { description: e.target.value })} /></td>
                      <td><input aria-label="Quantity" inputMode="decimal" className="field num w-[72px] text-right" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} /></td>
                      <td><input aria-label="Unit price" inputMode="decimal" className="field num w-[104px] text-right" value={l.unitPrice} onChange={(e) => update(l.key, { unitPrice: e.target.value })} /></td>
                      <td>
                        <select aria-label="Tax" className="field w-[116px]" value={l.taxRateId} onChange={(e) => update(l.key, { taxRateId: e.target.value })}>
                          {taxRates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                      </td>
                      <td className="num text-right font-semibold">{amt ? formatAmount(amt.subtotal, currency) : <span className="text-bad">check</span>}</td>
                      <td className="!pl-0"><button type="button" className="btn !px-3" aria-label={`Remove line ${l.description}`} onClick={() => removeLine(l.key)}>×</button></td>
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
              <div className="flex justify-between py-1.5"><dt>VAT</dt><dd className="num">{formatAmount(totals.tax, currency)}</dd></div>
              <div className="mt-1.5 flex justify-between border-t-2 border-ink pt-3 text-lg font-extrabold"><dt>Total due</dt><dd className="num font-semibold">{formatMoney(totals.total, currency)}</dd></div>
            </dl>
          </div>
        </Card>
      </div>

      <aside className="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
        <Card label="E-invoice clearance">
          <div className="flex items-center justify-between">
            <h2 className="text-[17px] font-bold">E-invoice clearance</h2>
            <Pill tone="warn">Ready</Pill>
          </div>
          <p className="mt-2 text-[13px] leading-relaxed text-muted">
            Approving posts this invoice and sends it to the tax authority. The registration number and QR code are added before your customer receives it.
          </p>
          <ol className="mt-2 text-sm">
            <li className="border-b border-line-soft py-3"><b>1. Checks passed</b><div className="text-[13px] text-muted">Seller and buyer TIN active · items mapped to tax codes</div></li>
            <li className="border-b border-line-soft py-3"><b>2. Submit for registration</b><div className="text-[13px] text-muted">Retried automatically if offline</div></li>
            <li className="py-3"><b>3. Registration number and QR</b><div className="text-[13px] text-muted">Printed on the PDF and stored on the invoice</div></li>
          </ol>
        </Card>
        <Card label="Posting preview">
          <h2 className="text-[17px] font-bold">On approval this posts</h2>
          <dl className="num mt-2 text-[13px]">
            <div className="flex justify-between py-1"><dt>Dr Accounts receivable</dt><dd>{formatAmount(totals.total, currency)}</dd></div>
            <div className="flex justify-between py-1"><dt>Cr Sales</dt><dd>{formatAmount(totals.subtotal, currency)}</dd></div>
            <div className="flex justify-between py-1"><dt>Cr VAT payable</dt><dd>{formatAmount(totals.tax, currency)}</dd></div>
          </dl>
        </Card>
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn">Save draft</button>
          <button type="button" className="btn btn-primary">Approve and send</button>
        </div>
      </aside>
    </div>
  );
}

function Field({ id, label, defaultValue, type = "text" }: { id: string; label: string; defaultValue: string; type?: string }) {
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <input className="field" id={id} type={type} defaultValue={defaultValue} />
    </div>
  );
}

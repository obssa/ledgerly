"use client";

import { useActionState } from "react";
import type { PriceListDetail } from "@/lib/api";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { formatAmount } from "@/lib/format";
import { createPriceList, savePriceList, savePrices } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

export function NewPriceList({ baseCurrency }: { baseCurrency: string }) {
  const [state, action, pending] = useActionState(createPriceList, undefined);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <div><label className="field-label" htmlFor="pl-name">Name</label><input className="field" id="pl-name" name="name" required minLength={2} maxLength={120} placeholder="Wholesale" /></div>
      <div>
        <label className="field-label" htmlFor="pl-currency">Currency</label>
        <select className="field" id="pl-currency" name="currency" defaultValue={baseCurrency}>
          {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <div><label className="field-label" htmlFor="pl-notes">Notes (optional)</label><input className="field" id="pl-notes" name="notes" maxLength={500} placeholder="Cafes and hotels" /></div>
      <button type="submit" className="btn btn-primary justify-center" disabled={pending}>{pending ? "Saving…" : "Add price list"}</button>
    </form>
  );
}

export function PriceListDetails({ list, readOnly }: { list: PriceListDetail; readOnly: boolean }) {
  const [state, action, pending] = useActionState(savePriceList, undefined);
  return (
    <form action={action} noValidate>
      <Message state={state} />
      <input type="hidden" name="id" value={list.id} />
      <fieldset disabled={readOnly} className="grid gap-3">
        <legend className="sr-only">Price list</legend>
        <div><label className="field-label" htmlFor="pl-name">Name</label><input className="field" id="pl-name" name="name" defaultValue={list.name} required minLength={2} maxLength={120} /></div>
        <div>
          <label className="field-label" htmlFor="pl-currency">Currency</label>
          <select className="field" id="pl-currency" name="currency" defaultValue={list.currency}>
            {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div><label className="field-label" htmlFor="pl-notes">Notes</label><input className="field" id="pl-notes" name="notes" defaultValue={list.notes ?? ""} maxLength={500} /></div>
        <label className="flex items-center gap-2 text-sm" htmlFor="pl-active">
          <input type="checkbox" id="pl-active" name="is_active" defaultChecked={list.is_active} /> In use
        </label>
        {!readOnly && <button type="submit" className="btn justify-center" disabled={pending}>{pending ? "Saving…" : "Save"}</button>}
      </fieldset>
    </form>
  );
}

/** One row per active item: a price puts it on the list, empty takes it off. */
export function PricesEditor({ list, items, readOnly }: { list: PriceListDetail; items: { id: string; name: string; sku: string | null; sale_price: string | null }[]; readOnly: boolean }) {
  const [state, action, pending] = useActionState(savePrices, undefined);
  const listed = new Map(list.prices.map((p) => [p.item_id, p.unit_price]));
  return (
    <form action={action} noValidate>
      <Message state={state} />
      <input type="hidden" name="id" value={list.id} />
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-[12px] text-muted"><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Own price</th><th className="py-2 text-right">Price on this list ({list.currency})</th></tr>
          </thead>
          <tbody>
            {items.map((i) => (
              <tr key={i.id} className="border-t border-line">
                <td className="py-1.5 pr-3">{i.name}{i.sku && <span className="ml-1 text-[12px] text-muted">{i.sku}</span>}</td>
                <td className="num py-1.5 pr-3 text-right text-muted">{i.sale_price ? formatAmount(i.sale_price) : "—"}</td>
                <td className="py-1.5 text-right">
                  <label className="sr-only" htmlFor={`price-${i.id}`}>Price of {i.name} on this list</label>
                  <input className="field num ml-auto w-[130px] text-right" id={`price-${i.id}`} name={`price.${i.id}`} inputMode="decimal" defaultValue={listed.get(i.id) ?? ""} placeholder="Not listed" disabled={readOnly} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!readOnly && <button type="submit" className="btn btn-primary mt-4" disabled={pending}>{pending ? "Saving…" : "Save prices"}</button>}
    </form>
  );
}

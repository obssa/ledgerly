"use client";

import Link from "next/link";
import { useActionState } from "react";
import type { Item } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { makeVariants } from "../actions";

/**
 * 089: an item sold in sizes, colours or other options. Each combination is a variant: an item of its own, with its own
 * stock, SKU, barcode and price, grouped under this one. Making them again adds only the combinations not made yet.
 */
export function VariantsPanel({ item }: { item: Item }) {
  const [state, action, pending] = useActionState(makeVariants, undefined);
  const variants = item.variants ?? [];
  if (item.parent) {
    return (
      <>
        <h2 className="text-[15px] font-bold">Variant</h2>
        <p className="mt-1 text-sm">A variant of <Link href={`/products/${encodeURIComponent(item.parent.id)}`} className="font-semibold underline">{item.parent.name}</Link>: {item.variant_label}.</p>
      </>
    );
  }
  return (
    <>
      <h2 className="text-[15px] font-bold">Variants</h2>
      {variants.length === 0 ? <p className="mt-1 text-[13px] text-muted">Sold in sizes, colours or other options? Make a variant for each; each keeps its own stock, code and price.</p> : (
        <ul className="mt-2 text-sm">
          {variants.map((v) => (
            <li key={v.id} className="flex justify-between gap-2 border-t border-line py-1.5">
              <Link href={`/products/${encodeURIComponent(v.id)}`} className="underline">{v.variant_label}</Link>
              <span className="num text-muted">{v.sale_price ? formatAmount(v.sale_price) : ""}{v.is_active ? "" : " (archived)"}</span>
            </li>
          ))}
        </ul>
      )}
      {item.can?.variants && (
        <form action={action} className="mt-3 border-t border-line pt-3">
          <input type="hidden" name="id" value={item.id} />
          {state?.error && <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
          {state?.notice && <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
          {[0, 1, 2].map((i) => (
            <div key={i} className="mb-2 grid grid-cols-[110px_1fr] gap-2">
              <div><label className="field-label" htmlFor={`opt-name-${i}`}>Option</label><input className="field" id={`opt-name-${i}`} name={`option_name_${i}`} maxLength={40} placeholder={["Size", "Colour", ""][i]} /></div>
              <div><label className="field-label" htmlFor={`opt-values-${i}`}>Values, separated by commas</label><input className="field" id={`opt-values-${i}`} name={`option_values_${i}`} placeholder={["S, M, L", "Red, Blue", ""][i]} /></div>
            </div>
          ))}
          <button type="submit" className="btn" disabled={pending}>{pending ? "Making…" : "Make the variants"}</button>
        </form>
      )}
    </>
  );
}

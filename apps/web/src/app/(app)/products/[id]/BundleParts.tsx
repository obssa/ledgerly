"use client";

import { useActionState, useState } from "react";
import type { Item } from "@/lib/api";
import { setBundleParts } from "../actions";

/**
 * 073: a bundle's parts, each with its quantity per bundle in the part's own unit. Selling the bundle takes its stock
 * parts out; non-stock parts move nothing. The parts are set while the bundle is unused and fixed once it is on a
 * document (make a new bundle to change them).
 */
export function BundleParts({ item, choices }: { item: Item; choices: { id: string; name: string; unit: string; type: string }[] }) {
  const [state, action, pending] = useActionState(setBundleParts, undefined);
  const current = item.components ?? [];
  const [rows, setRows] = useState(current.length > 0
    ? current.map((c, i) => ({ key: i, itemId: c.item_id, quantity: c.quantity }))
    : [{ key: 0, itemId: "", quantity: "1" }]);
  const editable = Boolean(item.can?.components);

  if (!editable) {
    return (
      <>
        <h2 className="text-[15px] font-bold">Parts</h2>
        {current.length === 0 ? <p className="mt-2 text-sm text-muted">No parts.</p> : (
          <ul className="mt-2 text-sm">
            {current.map((c) => (
              <li key={c.item_id} className="flex justify-between gap-2 border-t border-line py-1.5">
                <span>{c.name}{c.type !== "inventory" && <span className="ml-1 text-muted">(not stock)</span>}</span>
                <span className="num">{c.quantity} {c.unit}</span>
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-[13px] text-muted">{current.length > 0 ? "On documents already: its parts stay as they are. Make a new bundle to change them." : ""}</p>
      </>
    );
  }

  return (
    <form action={action}>
      <input type="hidden" name="id" value={item.id} />
      <h2 className="text-[15px] font-bold">Parts</h2>
      <p className="mt-1 text-[13px] text-muted">What one {item.name} is made of. Selling it takes its stock parts out at their cost.</p>
      {state?.error && <p role="alert" className="mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <div className="mt-2 flex flex-col gap-2">
        {rows.map((r, i) => {
          const unit = choices.find((c) => c.id === r.itemId)?.unit ?? "";
          return (
            <div key={r.key} className="flex items-center gap-2">
              <select aria-label={`Part ${i + 1}`} className="field min-w-0 flex-1" name={`part_item_${i}`} value={r.itemId}
                onChange={(e) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, itemId: e.target.value } : x)))}>
                <option value="">Choose an item</option>
                {choices.map((c) => <option key={c.id} value={c.id}>{c.name}{c.type === "inventory" ? " (stock)" : ""}</option>)}
              </select>
              <input aria-label={`Quantity of part ${i + 1}`} className="field num !w-[84px] text-right" name={`part_qty_${i}`} inputMode="decimal" value={r.quantity}
                onChange={(e) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, quantity: e.target.value } : x)))} />
              <span className="w-[48px] text-[13px] text-muted">{unit}</span>
              <button type="button" className="btn !px-3" aria-label={`Remove part ${i + 1}`} onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))}>×</button>
            </div>
          );
        })}
      </div>
      <div className="mt-2 flex gap-2">
        <button type="button" className="btn" disabled={rows.length >= 50}
          onClick={() => setRows((rs) => [...rs, { key: Math.max(0, ...rs.map((x) => x.key)) + 1, itemId: "", quantity: "1" }])}>+ Add part</button>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save parts"}</button>
      </div>
    </form>
  );
}

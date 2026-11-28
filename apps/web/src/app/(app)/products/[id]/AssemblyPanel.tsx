"use client";
import { DateInput } from "@/components/DateInput";

import { Dt } from "@/components/Dt";

import { useActionState, useState } from "react";
import type { ItemAssembly } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount } from "@/lib/format";
import { buildAssembly, setAssemblyParts } from "../actions";

/**
 * 087: a stock item made of other stock items. Its parts say how many of each go into one; building some takes the parts
 * out at their cost and puts the item in at the sum of it (nothing is posted: value moves from the parts to the item).
 */
export function AssemblyPanel({ itemId, itemName, assembly, choices, warehouses }: {
  itemId: string; itemName: string; assembly: ItemAssembly;
  choices: { id: string; name: string; unit: string }[]; warehouses: { id: string; name: string }[];
}) {
  const [partsState, saveParts, savingParts] = useActionState(setAssemblyParts, undefined);
  const [buildState, build, building] = useActionState(buildAssembly, undefined);
  const [rows, setRows] = useState(assembly.parts.length > 0
    ? assembly.parts.map((p, i) => ({ key: i, itemId: p.part_id, quantity: p.quantity }))
    : [{ key: 0, itemId: "", quantity: "1" }]);
  const today = todayIn();
  const message = (s: typeof partsState) => (s?.error ? <p role="alert" className="mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{s.error}</p>
    : s?.notice ? <p role="status" className="mt-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{s.notice}</p> : null);

  return (
    <>
      <h2 className="text-[15px] font-bold">Assembly</h2>
      <p className="mt-1 text-[13px] text-muted">If {itemName} is made from other stock items, list them: building it takes them out at their cost and puts {itemName} in at that cost.</p>
      {assembly.can.set_parts ? (
        <form action={saveParts}>
          <input type="hidden" name="id" value={itemId} />
          {message(partsState)}
          <div className="mt-2 flex flex-col gap-2">
            {rows.map((r, i) => (
              <div key={r.key} className="flex items-center gap-2">
                <select aria-label={`Part ${i + 1}`} className="field min-w-0 flex-1" name={`part_item_${i}`} value={r.itemId}
                  onChange={(e) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, itemId: e.target.value } : x)))}>
                  <option value="">Choose a stock product</option>
                  {choices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <input aria-label={`Quantity of part ${i + 1} in one`} className="field num !w-[84px] text-right" name={`part_qty_${i}`} inputMode="decimal" value={r.quantity}
                  onChange={(e) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, quantity: e.target.value } : x)))} />
                <span className="w-[48px] text-[13px] text-muted">{choices.find((c) => c.id === r.itemId)?.unit ?? ""}</span>
                <button type="button" className="btn !px-3" aria-label={`Remove part ${i + 1}`} onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))}>×</button>
              </div>
            ))}
          </div>
          <div className="mt-2 flex gap-2">
            <button type="button" className="btn" disabled={rows.length >= 50}
              onClick={() => setRows((rs) => [...rs, { key: Math.max(0, ...rs.map((x) => x.key)) + 1, itemId: "", quantity: "1" }])}>+ Add part</button>
            <button type="submit" className="btn" disabled={savingParts}>{savingParts ? "Saving…" : "Save parts"}</button>
          </div>
        </form>
      ) : assembly.parts.length > 0 && (
        <ul className="mt-2 text-sm">{assembly.parts.map((p) => <li key={p.part_id} className="flex justify-between border-t border-line py-1.5"><span>{p.name}</span><span className="num">{p.quantity} {p.unit}</span></li>)}</ul>
      )}

      {assembly.parts.length > 0 && assembly.can.build && (
        <form action={build} className="mt-4 border-t border-line pt-3">
          <input type="hidden" name="id" value={itemId} />
          {message(buildState)}
          <div className="grid grid-cols-2 gap-2">
            <div><label className="field-label" htmlFor="build-qty">Build how many</label><input className="field num" id="build-qty" name="quantity" inputMode="decimal" defaultValue="1" /></div>
            <div><label className="field-label" htmlFor="build-on">On</label><DateInput className="field num" id="build-on" name="built_on" max={today} defaultValue={today} /></div>
            <div>
              <label className="field-label" htmlFor="build-wh">In</label>
              <select className="field" id="build-wh" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
            </div>
            <div><label className="field-label" htmlFor="build-ref">Reference (optional)</label><input className="field" id="build-ref" name="reference" maxLength={100} /></div>
          </div>
          <button type="submit" className="btn btn-primary mt-2" disabled={building}>{building ? "Building…" : "Build"}</button>
        </form>
      )}

      {assembly.builds.length > 0 && (
        <ul className="mt-4 text-[13px]">
          {assembly.builds.map((b) => (
            <li key={b.id} className="flex justify-between gap-2 border-t border-line py-1.5">
              <span><span className="num"><Dt v={b.built_on} /></span> · {Number(b.quantity)} in {b.warehouse}{b.reference ? ` · ${b.reference}` : ""}</span>
              <span className="num">{b.value ? formatAmount(b.value) : ""}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

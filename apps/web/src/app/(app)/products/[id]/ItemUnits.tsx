"use client";

import { useActionState } from "react";
import type { Item } from "@/lib/api";
import { addItemUnit, archiveItemUnit } from "../actions";

/**
 * 072: the other units an item is bought or sold in, each a fixed number of its own unit. Stock is always counted in
 * the item's own unit; a line in a carton moves the carton's size of stock. A unit's name and size never change: an
 * outdated one is archived (documents already in it keep it) and a new one added.
 */
export function ItemUnits({ item }: { item: Item }) {
  const [added, add, adding] = useActionState(addItemUnit, undefined);
  const [archived, archive, archiving] = useActionState(archiveItemUnit, undefined);
  const units = item.units ?? [];
  const edit = Boolean(item.can?.edit);
  const flash = (s: typeof added) => (
    <>
      {s?.error && <p role="alert" className="mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{s.error}</p>}
      {s?.notice && <p role="status" className="mt-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{s.notice}</p>}
    </>
  );

  return (
    <>
      <h2 className="text-[15px] font-bold">Units</h2>
      <p className="mt-1 text-[13px] text-muted">Counted in <span className="font-semibold">{item.unit}</span>. Bought or sold in other units too:</p>
      {flash(archived)}
      {units.length === 0 ? <p className="mt-2 text-sm text-muted">None yet.</p> : (
        <ul className="mt-2 text-sm">
          {units.map((u) => (
            <li key={u.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5">
              <span>{u.name} <span className="num text-muted">= {Number(u.factor)} {item.unit}</span>{u.barcode && <span className="num ml-1 text-[12px] text-muted">· {u.barcode}</span>}{u.is_active === false && <span className="ml-1 text-muted">(archived)</span>}</span>
              {edit && u.is_active !== false && (
                <form action={archive}>
                  <input type="hidden" name="id" value={item.id} />
                  <input type="hidden" name="unit_id" value={u.id} />
                  <button type="submit" className="btn !px-3 !py-1 text-[13px]" disabled={archiving} aria-label={`Archive ${u.name}`}>Archive</button>
                </form>
              )}
            </li>
          ))}
        </ul>
      )}
      {edit && (
        <form action={add} className="mt-3 border-t border-line pt-3">
          <input type="hidden" name="id" value={item.id} />
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="field-label" htmlFor="unit-name">Unit</label>
              <input className="field" id="unit-name" name="name" maxLength={30} placeholder="Carton" required />
            </div>
            <div>
              <label className="field-label" htmlFor="unit-factor">{item.unit} in one</label>
              <input className="field num" id="unit-factor" name="factor" inputMode="decimal" placeholder="12" required />
            </div>
            <div className="col-span-2">
              <label className="field-label" htmlFor="unit-barcode">Its barcode (optional)</label>
              <input className="field num" id="unit-barcode" name="barcode" maxLength={64} />
            </div>
          </div>
          {flash(added)}
          <button type="submit" className="btn mt-2" disabled={adding}>{adding ? "Adding…" : "Add unit"}</button>
        </form>
      )}
    </>
  );
}

"use client";

import { useActionState } from "react";
import { setBinLocation } from "../actions";

/** 090: where the item is kept in a warehouse ("Aisle 3 / B2"); empty clears it. */
export function BinLocations({ itemId, warehouses, locations }: {
  itemId: string; warehouses: { id: string; name: string }[]; locations: { warehouse_id: string; warehouse: string; bin: string }[];
}) {
  const [state, action, pending] = useActionState(setBinLocation, undefined);
  return (
    <form action={action} className="mt-3 border-t border-line pt-3">
      <input type="hidden" name="id" value={itemId} />
      <h3 className="text-[13px] font-bold">Bin locations</h3>
      {state?.error && <p role="alert" className="mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {locations.length > 0 && (
        <ul className="mt-1 text-[13px]">{locations.map((l) => <li key={l.warehouse_id}>{l.warehouse}: <span className="font-semibold">{l.bin}</span></li>)}</ul>
      )}
      <div className="mt-2 flex flex-wrap items-end gap-2">
        <div>
          <label className="field-label" htmlFor="bin-wh">Warehouse</label>
          <select className="field" id="bin-wh" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
        </div>
        <div><label className="field-label" htmlFor="bin-code">Bin</label><input className="field w-[150px]" id="bin-code" name="bin" maxLength={40} placeholder="Aisle 3 / B2" /></div>
        <button type="submit" className="btn" disabled={pending}>{pending ? "Saving…" : "Set"}</button>
      </div>
      <p className="mt-1 text-[12px] text-muted">Leave the bin empty to clear it.</p>
    </form>
  );
}

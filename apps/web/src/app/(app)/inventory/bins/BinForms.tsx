"use client";

import { useActionState } from "react";
import type { Bin } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { addBin, changeBin, setBinItems } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** 098: a new bin in one of the warehouses. */
export function AddBin({ warehouses }: { warehouses: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(addBin, undefined);
  return (
    <form action={action} noValidate>
      <Message state={state} />
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <label className="field-label" htmlFor="bin-warehouse">Warehouse</label>
          <select className="field" id="bin-warehouse" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
        </div>
        <div><label className="field-label" htmlFor="bin-code">Code</label><input className="field w-[130px]" id="bin-code" name="code" maxLength={30} placeholder="A-01" /></div>
        <div className="min-w-[180px] flex-1"><label className="field-label" htmlFor="bin-name">Description (optional)</label><input className="field" id="bin-name" name="name" maxLength={120} placeholder="Top shelf, first aisle" /></div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Adding…" : "Add bin"}</button>
      </div>
    </form>
  );
}

/** 098: a bin, the items kept in it, and (to who manages items) its code, its items, and archiving it once empty. */
export function BinRow({ bin, stock, manage }: { bin: Bin; stock: { id: string; name: string; sku: string | null }[]; manage: boolean }) {
  const [state, rename, renaming] = useActionState(changeBin, undefined);
  const [toggleState, toggle, toggling] = useActionState(changeBin, undefined);
  const [itemsState, keep, keeping] = useActionState(setBinItems, undefined);
  const kept = new Set(bin.items.map((i) => i.id));
  const listed = bin.items.length === 0 ? "Nothing kept here." : bin.items.map((i) => i.name).join(", ");
  return (
    <li className="border-t border-line py-2.5">
      <Message state={state ?? toggleState ?? itemsState} />
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="num font-bold">{bin.code}</span>
        {bin.name && <span className="text-[13px] text-muted">{bin.name}</span>}
        {!bin.is_active && <span className="text-[12px] font-semibold text-muted">Archived</span>}
      </div>
      <p className="mt-1 text-[13px]">{listed}</p>
      {manage && (
        <details className="mt-1.5">
          <summary className="cursor-pointer text-[13px] font-semibold text-brand">Change</summary>
          <div className="mt-2 grid gap-3">
            <form action={rename} noValidate className="flex flex-wrap items-end gap-2">
              <input type="hidden" name="id" value={bin.id} />
              <input className="field w-[130px]" name="code" maxLength={30} defaultValue={bin.code} aria-label={`Code of bin ${bin.code}`} />
              <input className="field min-w-[180px] flex-1" name="name" maxLength={120} defaultValue={bin.name ?? ""} aria-label={`Description of bin ${bin.code}`} />
              <button type="submit" className="btn" disabled={renaming}>Save</button>
            </form>
            {bin.is_active && stock.length > 0 && (
              <form action={keep} noValidate className="grid gap-2">
                <input type="hidden" name="id" value={bin.id} />
                <fieldset>
                  <legend className="field-label">Kept in {bin.code} (an item ticked here leaves its other bin in this warehouse)</legend>
                  <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
                    {stock.map((s) => (
                      <label key={s.id} className="flex items-center gap-1.5"><input type="checkbox" name="item_ids" value={s.id} defaultChecked={kept.has(s.id)} />{s.sku ? `${s.sku} ` : ""}{s.name}</label>
                    ))}
                  </div>
                </fieldset>
                <div><button type="submit" className="btn" disabled={keeping}>{keeping ? "Saving…" : "Save products"}</button></div>
              </form>
            )}
            <form action={toggle}>
              <input type="hidden" name="id" value={bin.id} />
              <input type="hidden" name="is_active" value={bin.is_active ? "0" : "1"} />
              <button type="submit" className="btn" disabled={toggling}>{bin.is_active ? "Archive" : "Bring back"}</button>
            </form>
          </div>
        </details>
      )}
    </li>
  );
}

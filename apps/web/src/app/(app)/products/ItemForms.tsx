"use client";

import { useActionState } from "react";
import type { Item } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { adjustStock, createItem, createWarehouse, saveItem, transferStock, issueStock } from "./actions";
import { todayIn } from "@/lib/dates";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

const TYPES: Record<string, string> = {
  inventory: "Stock item (kept in stock, with a bin card)", non_inventory: "Non-stock item", service: "Service",
  bundle: "Bundle (sold as one, made of other items)",
};

/** New item, or the details of one. Type is fixed once the item has stock moves (the API says so). */
export function ItemForm({ item, readOnly = false, suppliers = [] }: { item?: Item; readOnly?: boolean; suppliers?: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(item ? saveItem : createItem, undefined);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate>
      <Message state={state} />
      {item && <input type="hidden" name="id" value={item.id} />}
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
        <legend className="sr-only">Item</legend>
        <Field name="name" label="Name" defaultValue={item?.name} invalid={bad("name")} />
        <Field name="sku" label="SKU or code" defaultValue={item?.sku ?? ""} invalid={bad("sku")} />
        <div>
          <label className="field-label" htmlFor="type">Type</label>
          <select className={`field ${bad("type") ? "!border-bad" : ""}`} id="type" name="type" defaultValue={item?.type ?? "inventory"}>
            {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </div>
        <Field name="unit" label="Unit" defaultValue={item?.unit ?? "each"} hint="each, kg, bag, carton" />
        <Field name="sale_price" label="Sale price" defaultValue={item?.sale_price ?? ""} invalid={bad("sale_price")} />
        <Field name="purchase_price" label="Purchase price" defaultValue={item?.purchase_price ?? ""} invalid={bad("purchase_price")} />
        <Field name="reorder_point" label="Reorder when on hand falls to" defaultValue={item?.reorder_point ?? ""} invalid={bad("reorder_point")} />
        <Field name="max_level" label="Order back up to" defaultValue={item?.max_level ?? ""} invalid={bad("max_level")} hint="The maximum level a suggested order brings it to" />
        <div>
          <label className="field-label" htmlFor="preferred_supplier_id">Preferred supplier</label>
          <select className={`field ${bad("preferred_supplier_id") ? "!border-bad" : ""}`} id="preferred_supplier_id" name="preferred_supplier_id" defaultValue={item?.preferred_supplier_id ?? ""}>
            <option value="">None</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <p className="mt-1 text-[12px] text-muted">Who a suggested purchase order goes to.</p>
        </div>
        <div>
          <label className="field-label" htmlFor="costing">Costing (stock items)</label>
          <select className={`field ${bad("costing") ? "!border-bad" : ""}`} id="costing" name="costing" defaultValue={item?.costing ?? "average"}>
            <option value="average">Weighted average</option>
            <option value="fifo">FIFO (first in, first out)</option>
          </select>
          <p className="mt-1 text-[12px] text-muted">Chosen before the first stock move, and kept.</p>
        </div>
        <div>
          <label className="field-label" htmlFor="tracking">Batches (stock items)</label>
          <select className={`field ${bad("tracking") ? "!border-bad" : ""}`} id="tracking" name="tracking" defaultValue={item?.tracking ?? "none"}>
            <option value="none">Not tracked</option>
            <option value="batch">Tracked by batch, with expiry</option>
          </select>
          <p className="mt-1 text-[12px] text-muted">Food, medicine, chemicals. Chosen before the first stock move, and kept.</p>
        </div>
      </fieldset>
      {!readOnly && <button type="submit" className="btn btn-primary mt-6" disabled={pending}>{pending ? "Saving…" : item ? "Save item" : "Add item"}</button>}
    </form>
  );
}

/** Opening stock, a count difference, damage: moves stock with a reason and posts its value. */
export function AdjustStock({ item, warehouses }: { item: Item; warehouses: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(adjustStock, undefined);
  const today = todayIn();
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="flex flex-col gap-3">
      <Message state={state} />
      <input type="hidden" name="item_id" value={item.id} />
      <div>
        <label className="field-label" htmlFor="warehouse_id">Warehouse</label>
        <select className="field" id="warehouse_id" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
      </div>
      <Field name="quantity" label="Quantity (minus to take out)" invalid={bad("quantity")} />
      <Field name="unit_cost" label="Unit cost (stock coming in)" invalid={bad("unit_cost")} hint="Stock going out is valued at the average cost" />
      {item.tracking === "batch" && (
        <>
          <Field name="batch" label="Batch" invalid={bad("batch")} hint="Coming in: its batch number. Going out: empty takes the first to expire" />
          <Field name="expires_on" label="Expires on (coming in)" type="date" invalid={bad("expires_on")} />
        </>
      )}
      <Field name="date" label="Date" type="date" defaultValue={today} invalid={bad("date")} />
      <Field name="reason" label="Reason" invalid={bad("reason")} hint="For example: count on 30 Nov, bags torn" />
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="opening" className="size-4" /> Opening stock (against opening balance equity)</label>
      <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Recording…" : "Record"}</button>
    </form>
  );
}

export function NewWarehouse() {
  const [state, action, pending] = useActionState(createWarehouse, undefined);
  return (
    <form action={action} noValidate className="flex flex-wrap items-end gap-2.5">
      <div className="min-w-[200px] flex-1">
        <Message state={state} />
        <label className="field-label" htmlFor="wh_name">New warehouse, shop or van</label>
        <input className="field" id="wh_name" name="name" maxLength={120} />
      </div>
      <button type="submit" className="btn" disabled={pending}>Add</button>
    </form>
  );
}

/** Moves stock between warehouses at its average cost; nothing posts to the ledger. */
export function TransferStock({ items, warehouses }: { items: { id: string; name: string }[]; warehouses: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(transferStock, undefined);
  const today = todayIn();
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
      <div className="col-span-full"><Message state={state} /></div>
      <div>
        <label className="field-label" htmlFor="t_item">Item</label>
        <select className="field" id="t_item" name="item_id">{items.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="t_from">From</label>
        <select className="field" id="t_from" name="from_warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="t_to">To</label>
        <select className={`field ${bad("to_warehouse_id") ? "!border-bad" : ""}`} id="t_to" name="to_warehouse_id" defaultValue={warehouses[1]?.id}>
          {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
        </select>
      </div>
      <Field name="quantity" label="Quantity" invalid={bad("quantity")} />
      <Field name="date" label="Date" type="date" defaultValue={today} />
      <button type="submit" className="btn" disabled={pending}>{pending ? "Moving…" : "Move stock"}</button>
    </form>
  );
}

/** Module 9a: materials from a warehouse for a project; they leave at the warehouse's cost and count in its profit. */
export function IssueToProject({ items, warehouses, projects, costAccounts }: {
  items: { id: string; name: string }[]; warehouses: { id: string; name: string }[];
  projects: { id: string; code: string; name: string }[]; costAccounts: { id: string; code: string; name: string }[];
}) {
  const [state, action, pending] = useActionState(issueStock, undefined);
  const today = todayIn();
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] items-end gap-3">
      <div className="col-span-full"><Message state={state} /></div>
      <div>
        <label className="field-label" htmlFor="i_item">Item</label>
        <select className="field" id="i_item" name="item_id">{items.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="i_from">From</label>
        <select className={`field ${bad("warehouse_id") ? "!border-bad" : ""}`} id="i_from" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="i_project">Project</label>
        <select className={`field ${bad("project_id") ? "!border-bad" : ""}`} id="i_project" name="project_id">{projects.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}</select>
      </div>
      <div>
        <label className="field-label" htmlFor="i_account">Cost account</label>
        <select className={`field ${bad("account_id") ? "!border-bad" : ""}`} id="i_account" name="account_id">{costAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
      </div>
      <Field name="quantity" label="Quantity" invalid={bad("quantity")} />
      <Field name="date" label="Date" type="date" defaultValue={today} />
      <button type="submit" className="btn" disabled={pending}>{pending ? "Issuing…" : "Issue to project"}</button>
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

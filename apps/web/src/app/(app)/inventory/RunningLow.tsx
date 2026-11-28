"use client";

import Link from "@/components/Link";
import { useActionState } from "react";
import { formatAmount } from "@/lib/format";
import { orderLowStock } from "../products/actions";

interface Row {
  item: { id: string; name: string; unit: string; reorder_point: string; max_level: string | null; purchase_price: string | null };
  supplier: { id: string; name: string | null } | null;
  on_hand: string;
  on_order: string;
  reserved: string;
  position: string;
  suggested: string;
}

/**
 * 075: the stock items at or below their reorder point, counting what is on order and what open sales orders hold,
 * with the order that brings each back up to its maximum. Ticked items become draft purchase orders, one per
 * preferred supplier, at each item's purchase price; nothing posts.
 */
export function RunningLow({ rows, warehouses }: { rows: Row[]; warehouses: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(orderLowStock, undefined);
  return (
    <form action={action}>
      <h2 className="mb-1 text-[15px] font-bold text-warn">Running low</h2>
      <p className="mb-2 text-[13px] text-muted">On hand, plus on order, less what open sales orders hold. Tick what to order: it becomes a draft purchase order to each product&apos;s supplier.</p>
      {state?.error && <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr><th scope="col"><span className="sr-only">Order</span></th><th scope="col">Product</th><th scope="col" className="!text-right">On hand</th>
              <th scope="col" className="!text-right">On order</th><th scope="col" className="!text-right">Held</th><th scope="col" className="!text-right">Reorder at</th>
              <th scope="col">Supplier</th><th scope="col" className="!text-right">Order</th></tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.item.id}>
                <td><input type="checkbox" name={`order_${r.item.id}`} aria-label={`Order ${r.item.name}`} className="size-4" disabled={!r.supplier} defaultChecked={Boolean(r.supplier) && Number(r.suggested) > 0} /></td>
                <td><Link href={`/products/${encodeURIComponent(r.item.id)}`} className="font-semibold hover:underline">{r.item.name}</Link></td>
                <td className="num text-right">{formatAmount(r.on_hand)}</td>
                <td className="num text-right">{formatAmount(r.on_order)}</td>
                <td className="num text-right">{formatAmount(r.reserved)}</td>
                <td className="num text-right">{formatAmount(r.item.reorder_point)}{r.item.max_level ? <span className="text-muted"> → {formatAmount(r.item.max_level)}</span> : null}</td>
                <td>{r.supplier?.name ?? <Link href={`/products/${encodeURIComponent(r.item.id)}`} className="text-[13px] text-muted underline">Set a supplier</Link>}</td>
                <td className="text-right">
                  <input className="field num !w-[90px] text-right" name={`qty_${r.item.id}`} defaultValue={r.suggested} inputMode="decimal" aria-label={`Quantity of ${r.item.name}`} />
                  <span className="ml-1 text-[13px] text-muted">{r.item.unit}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="mt-3 flex flex-wrap items-end gap-2">
        <div>
          <label className="field-label" htmlFor="low-warehouse">Arriving in</label>
          <select className="field" id="low-warehouse" name="warehouse_id">{warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>
        </div>
        <button type="submit" className="btn btn-primary" disabled={pending || warehouses.length === 0}>{pending ? "Making…" : "Make the purchase orders"}</button>
      </div>
    </form>
  );
}

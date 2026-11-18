"use client";

import Link from "next/link";
import { useActionState } from "react";
import type { LandedCosts } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount, formatMoney } from "@/lib/format";
import { applyLandedCost } from "../actions";

/**
 * Module 4: landed costs (060). On a bill for freight, duty or clearing: add it to the cost of the goods another bill
 * brought in. On a bill that brought goods in: the costs added to them.
 */
export function LandedCostPanel({ billId, landed, base }: { billId: string; landed: LandedCosts; base: string }) {
  const [state, action, pending] = useActionState(applyLandedCost, undefined);
  const canSpread = landed.can.apply && Number(landed.left) > 0 && landed.candidates.length > 0;

  return (
    <div className="rounded-xl border border-line p-3">
      <h2 className="text-[15px] font-bold">Landed cost</h2>
      {landed.received.length > 0 && (
        <ul className="mt-1.5 text-[13px]">
          {landed.received.map((r) => (
            <li key={r.id}>
              <span className="num font-semibold">{formatMoney(r.amount, base)}</span> added from{" "}
              <Link href={`/bills/${encodeURIComponent(r.cost_bill_id)}`} className="num font-semibold hover:underline">{r.cost_bill_number}</Link> on <span className="num">{r.applied_on}</span>
            </li>
          ))}
        </ul>
      )}
      {landed.spread.length > 0 && (
        <ul className="mt-1.5 text-[13px]">
          {landed.spread.map((r) => (
            <li key={r.id}>
              <span className="num font-semibold">{formatMoney(r.amount, base)}</span> added to the goods of{" "}
              <Link href={`/bills/${encodeURIComponent(r.goods_bill_id)}`} className="num font-semibold hover:underline">{r.goods_bill_number}</Link> ({r.method === "quantity" ? "by quantity" : "by value"})
            </li>
          ))}
        </ul>
      )}
      {canSpread ? (
        <form action={action} className="mt-2 grid gap-2">
          <input type="hidden" name="id" value={billId} />
          <p className="text-[13px] text-muted">
            Freight, duty or clearing for goods another bill brought in: add it to their cost. Goods still on hand take it onto their value;
            goods already sold take it into cost of goods sold. <span className="num">{formatAmount(landed.left)}</span> left to add.
          </p>
          {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
          {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
          {state?.fields && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] text-bad">{Object.values(state.fields).flat().join(" ")}</p>}
          <div>
            <label className="field-label" htmlFor="goods_bill_id">The goods of</label>
            <select className="field" id="goods_bill_id" name="goods_bill_id">
              {landed.candidates.map((b) => (
                <option key={b.id} value={b.id}>{b.number}{b.reference ? ` (${b.reference})` : ""} · {b.supplier} · {b.issue_date}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-wrap gap-2">
            <div>
              <label className="field-label" htmlFor="method">Spread by</label>
              <select className="field" id="method" name="method" defaultValue="value">
                <option value="value">Value</option>
                <option value="quantity">Quantity</option>
              </select>
            </div>
            <div>
              <label className="field-label" htmlFor="lc_amount">Amount</label>
              <input className="field num w-[130px]" id="lc_amount" name="amount" inputMode="decimal" placeholder={landed.left} />
            </div>
            <div>
              <label className="field-label" htmlFor="lc_date">Date</label>
              <input className="field" id="lc_date" name="date" type="date" defaultValue={todayIn()} max={todayIn()} />
            </div>
          </div>
          <div><button type="submit" className="btn" disabled={pending}>{pending ? "Adding…" : "Add to the cost of the goods"}</button></div>
        </form>
      ) : landed.spread.length === 0 && landed.received.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">
          {Number(landed.left) > 0 && landed.candidates.length === 0 ? "No posted bill has brought goods in yet." : "Nothing added."}
        </p>
      ) : null}
    </div>
  );
}

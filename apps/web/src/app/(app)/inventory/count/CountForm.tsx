"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import { todayIn } from "@/lib/dates";
import { countStock } from "../../products/actions";

/** The count sheet: what the books say, and what was counted (an empty line is not counted). 098: of one bin, or showing each item's bin. */
export function CountForm({ warehouseId, binId, showBins, lines }: {
  warehouseId: string; binId?: string; showBins?: boolean; lines: { id: string; name: string; sku: string | null; unit: string; books: string; bin?: string | null }[];
}) {
  const [state, action, pending] = useActionState(countStock, undefined);
  const today = todayIn();
  return (
    <form action={action} className="grid gap-3">
      <input type="hidden" name="warehouse_id" value={warehouseId} />
      {binId && <input type="hidden" name="bin_id" value={binId} />}
      {state?.error && <p role="alert" className="mx-3 mt-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mx-3 mt-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead><tr>{showBins && <th scope="col">Bin</th>}<th scope="col">Product</th><th scope="col" className="!text-right">In the books</th><th scope="col">Counted</th></tr></thead>
          <tbody>
            {lines.map((l) => (
              <tr key={l.id}>
                {showBins && <td className="num text-muted">{l.bin ?? "None"}</td>}
                <td>{l.sku && <span className="num text-muted">{l.sku} </span>}{l.name}</td>
                <td className="num text-right">{l.books} <span className="text-[12px] text-muted">{l.unit}</span></td>
                <td>
                  <label className="sr-only" htmlFor={`c-${l.id}`}>Counted {l.name}</label>
                  <input className="field num w-[120px]" id={`c-${l.id}`} name={`count_${l.id}`} inputMode="decimal" placeholder={l.books} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex flex-wrap items-end gap-2.5 px-3 pb-3">
        <div><label className="field-label" htmlFor="date">Counted on</label><DateInput className="field" id="date" name="date" max={today} defaultValue={today} /></div>
        <div className="min-w-[220px] flex-1"><label className="field-label" htmlFor="note">Note</label><input className="field" id="note" name="note" maxLength={300} placeholder="Month-end count" /></div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Recording…" : "Record the count"}</button>
      </div>
      <p className="px-3 pb-3 text-[13px] text-muted">Each difference is adjusted at cost and posted in one entry to inventory adjustment.</p>
    </form>
  );
}

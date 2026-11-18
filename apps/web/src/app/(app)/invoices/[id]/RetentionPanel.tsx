"use client";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceRetention } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount } from "@/lib/format";
import { holdRetention, releaseRetention } from "../actions";

/**
 * Module 9a, construction: retention the customer holds back on this invoice until the work is complete. Held, it is
 * owed later rather than now; released, the invoice owes it again.
 */
export function RetentionPanel({ invoiceId, retentions, canHold, canManage }: { invoiceId: string; retentions: InvoiceRetention[]; canHold: boolean; canManage: boolean }) {
  const [holdState, hold, holding] = useActionState(holdRetention, undefined);
  const [releaseState, release, releasing] = useActionState(releaseRetention, undefined);
  const state = holdState ?? releaseState;
  const held = retentions.filter((r) => r.released_on === null);
  const heldTotal = held.reduce((sum, r) => sum + Number(r.amount), 0);
  const today = todayIn();
  return (
    <Card label="Retention">
      <h2 className="text-[17px] font-bold">Retention</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {retentions.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">None held. If the customer holds back part of this invoice until the work is complete, record it here.</p>
      ) : (
        <ul className="mt-2 flex flex-col gap-1 text-[13px]">
          {retentions.map((r) => (
            <li key={r.id}>
              <span className="num font-semibold">{formatAmount(r.amount)}</span> held on <span className="num">{r.held_on}</span>
              {r.released_on ? <>, released on <span className="num">{r.released_on}</span></> : ", still held"}
            </li>
          ))}
        </ul>
      )}
      {canManage && canHold && (
        <form action={hold} noValidate className="mt-3 flex flex-wrap items-end gap-2">
          <input type="hidden" name="id" value={invoiceId} />
          <div><label className="field-label" htmlFor="r-percent">%</label><input className="field num w-[70px]" id="r-percent" name="percent" inputMode="decimal" placeholder="10" /></div>
          <div><label className="field-label" htmlFor="r-amount">or amount</label><input className="field num w-[110px]" id="r-amount" name="amount" inputMode="decimal" /></div>
          <div><label className="field-label" htmlFor="r-held">Held on</label><input className="field" id="r-held" name="held_on" type="date" max={today} defaultValue={today} /></div>
          <button type="submit" className="btn" disabled={holding}>{holding ? "Saving…" : "Hold"}</button>
        </form>
      )}
      {canManage && held.length > 0 && (
        <form action={release} className="mt-3 flex flex-wrap items-end gap-2 border-t border-line pt-3">
          <input type="hidden" name="id" value={invoiceId} />
          <div><label className="field-label" htmlFor="r-released">Released on</label><input className="field" id="r-released" name="released_on" type="date" max={today} defaultValue={today} /></div>
          <button type="submit" className="btn btn-primary" disabled={releasing}>{releasing ? "Releasing…" : `Release ${formatAmount(heldTotal.toFixed(2))}`}</button>
        </form>
      )}
    </Card>
  );
}

"use client";

import { Dt } from "@/components/Dt";

import { useActionState, useState } from "react";
import type { Reconciliation } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { reconcileTotals } from "@/lib/reconcile";
import { discardReconciliation, saveReconciliation, startReconciliation, undoReconciliation } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

const grouped = (v: string) => {
  const negative = v.startsWith("-");
  const [whole, cents = "00"] = (negative ? v.slice(1) : v).split(".");
  return `${negative ? "−" : ""}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",")}.${cents}`;
};

/** Start reconciling: the statement's last date and its closing balance, as printed on it. */
export function StartReconciliation({ accountId, opening, today }: { accountId: string; opening: string; today: string }) {
  const [state, action, pending] = useActionState(startReconciliation, undefined);
  return (
    <form action={action} noValidate className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] items-end gap-4">
      <div className="col-span-full"><Message state={state} /></div>
      <input type="hidden" name="account_id" value={accountId} />
      <div>
        <label className="field-label" htmlFor="statement_date">Statement ends on</label>
        <input className="field" id="statement_date" name="statement_date" type="date" max={today} defaultValue={today} />
      </div>
      <div>
        <label className="field-label" htmlFor="ending_balance">Ending balance on the statement</label>
        <input className="field num" id="ending_balance" name="ending_balance" inputMode="decimal" placeholder="0.00" aria-describedby="opening-hint" />
      </div>
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Starting…" : "Start reconciling"}</button></div>
      <p id="opening-hint" className="col-span-full text-[13px] text-muted">Starts from <span className="num">{grouped(opening)}</span>, where the last reconciliation ended. Entries already matched to statement lines are ticked for you.</p>
    </form>
  );
}

/** The workspace: tick what the statement shows until the difference is zero, then finish. */
export function ReconcileWorkspace({ r }: { r: Reconciliation }) {
  const [state, action, pending] = useActionState(saveReconciliation, undefined);
  const lines = r.lines ?? [];
  const [ticked, setTicked] = useState<Record<string, boolean>>(() => Object.fromEntries(lines.map((l) => [l.journal_line_id ?? "", Boolean(l.cleared)])));
  const [ending, setEnding] = useState(r.ending_balance);
  const totals = reconcileTotals(r.opening_balance, ending, lines.map((l) => ({ amount: l.amount, cleared: Boolean(ticked[l.journal_line_id ?? ""]) })));
  const balanced = totals.difference === "0.00";
  const all = lines.length > 0 && lines.every((l) => ticked[l.journal_line_id ?? ""]);

  return (
    <form action={action} noValidate>
      <Message state={state} />
      <input type="hidden" name="id" value={r.id} />
      <div className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-4">
        <div>
          <label className="field-label" htmlFor="statement_date">Statement ends on</label>
          <input className="field" id="statement_date" name="statement_date" type="date" defaultValue={r.statement_date} />
        </div>
        <div>
          <label className="field-label" htmlFor="ending_balance">Ending balance ({r.account.currency})</label>
          <input className="field num" id="ending_balance" name="ending_balance" inputMode="decimal" value={ending} onChange={(e) => setEnding(e.target.value)} />
        </div>
        <dl className="col-span-full grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3 rounded-lg bg-line-soft p-3 text-[13px]" aria-live="polite">
          <div><dt className="text-muted">Opening</dt><dd className="num font-semibold">{grouped(r.opening_balance)}</dd></div>
          <div><dt className="text-muted">Ticked in</dt><dd className="num font-semibold">{grouped(totals.clearedIn)}</dd></div>
          <div><dt className="text-muted">Ticked out</dt><dd className="num font-semibold">{grouped(totals.clearedOut)}</dd></div>
          <div><dt className="text-muted">Ticked balance</dt><dd className="num font-semibold">{grouped(totals.clearedBalance)}</dd></div>
          <div><dt className="text-muted">Difference</dt><dd className={`num text-[15px] font-bold ${balanced ? "text-good" : "text-bad"}`}>{grouped(totals.difference)}</dd></div>
        </dl>
      </div>
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr>
              <th scope="col" className="w-10">
                <input type="checkbox" aria-label="Tick every entry" checked={all}
                  onChange={(e) => setTicked(Object.fromEntries(lines.map((l) => [l.journal_line_id ?? "", e.target.checked])))} />
              </th>
              <th scope="col">Date</th><th scope="col">Entry</th><th scope="col">What</th><th scope="col">Who</th><th scope="col" className="!text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 && <tr><td colSpan={6} className="text-muted">No entries on this account up to the statement date.</td></tr>}
            {lines.map((l) => {
              const id = l.journal_line_id ?? "";
              return (
                <tr key={id}>
                  <td><input type="checkbox" name="cleared" value={id} id={`c-${id}`} checked={Boolean(ticked[id])} onChange={(e) => setTicked({ ...ticked, [id]: e.target.checked })} /></td>
                  <td className="num"><label htmlFor={`c-${id}`}><Dt v={l.date} /></label></td>
                  <td className="num">#{l.entry}</td>
                  <td>{l.memo ?? l.source_type}{l.on_statement && <span className="ml-2 text-[12px] font-semibold text-brand">on the statement</span>}</td>
                  <td>{l.contact ?? "—"}</td>
                  <td className={`num text-right ${l.amount.startsWith("-") ? "" : "text-good"}`}>{grouped(l.amount)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {r.can.reconcile && (
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <button type="submit" name="intent" value="save" className="btn" disabled={pending}>Save for later</button>
          <button type="submit" name="intent" value="finish" className="btn btn-primary" disabled={pending}>Finish reconciliation</button>
          {!balanced && <span className="text-[13px] text-muted">Finishing needs a difference of 0.00.</span>}
        </div>
      )}
    </form>
  );
}

export function DiscardReconciliation({ id, accountId }: { id: string; accountId: string }) {
  const [state, action, pending] = useActionState(discardReconciliation, undefined);
  return (
    <form action={action} className="flex flex-wrap items-end gap-2">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="account_id" value={accountId} />
      <div className="min-w-[240px] flex-1">
        <label className="field-label" htmlFor="discard-reason">Why discard it</label>
        <input className="field" id="discard-reason" name="reason" required minLength={3} maxLength={300} placeholder="Started with the wrong statement" />
      </div>
      <button type="submit" className="btn" disabled={pending}>Discard</button>
    </form>
  );
}

export function UndoReconciliation({ id }: { id: string }) {
  const [state, action, pending] = useActionState(undoReconciliation, undefined);
  return (
    <form action={action} className="flex flex-wrap items-end gap-2">
      <div className="col-span-full w-full"><Message state={state} /></div>
      <input type="hidden" name="id" value={id} />
      <div className="min-w-[240px] flex-1">
        <label className="field-label" htmlFor="undo-reason">Why undo it</label>
        <input className="field" id="undo-reason" name="reason" required minLength={3} maxLength={300} placeholder="The bank sent a corrected statement" />
      </div>
      <button type="submit" className="btn" disabled={pending}>Undo reconciliation</button>
    </form>
  );
}

"use client";

import { useActionState } from "react";
import type { BankAccount, TaxKind } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { fileTaxReturn, settleTaxReturn } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** File the return for the period shown: the date it went to the authority and its reference. */
export function FileReturn({ kind, from, to }: { kind: TaxKind; from: string; to: string }) {
  const [state, action, pending] = useActionState(fileTaxReturn, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="kind" value={kind} />
      <input type="hidden" name="period_start" value={from} />
      <input type="hidden" name="period_end" value={to} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="filed_on">Filed on</label><input className="field" id="filed_on" name="filed_on" type="date" min={to} max={today} defaultValue={today} /></div>
        <div className="min-w-[220px]">
          <label className="field-label" htmlFor="reference">Authority&apos;s reference (optional)</label>
          <input className="field" id="reference" name="reference" maxLength={100} placeholder="The receipt or acknowledgement number" />
        </div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Filing…" : "Record as filed"}</button>
      </div>
      <p className="text-[13px] text-muted">
        The figures above are kept with the return as they are now. After this, nothing posts to the tax accounts dated inside the period: a correction goes into a later return.
      </p>
    </form>
  );
}

/** Settle a filed return: VAT payable against VAT receivable, and the rest paid from a bank, wallet or cash account. */
export function SettleReturn({ id, periodEnd, due, banks }: { id: string; periodEnd: string; due: string; banks: BankAccount[] }) {
  const [state, action, pending] = useActionState(settleTaxReturn, undefined);
  const today = todayIn();
  const paying = !due.startsWith("-") && due !== "0.00";
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="settle_date">Paid on</label><input className="field" id="settle_date" name="date" type="date" min={periodEnd} max={today} defaultValue={today} /></div>
        {paying && (
          <div className="min-w-[220px]">
            <label className="field-label" htmlFor="bank_account_id">Paid from</label>
            <select className="field" id="bank_account_id" name="bank_account_id" defaultValue="">
              <option value="">Choose…</option>
              {banks.map((b) => <option key={b.id} value={b.id}>{`${b.code} ${b.name}`}</option>)}
            </select>
          </div>
        )}
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Settling…" : paying ? `Record payment of ${due}` : "Set the input VAT against it"}</button>
      </div>
    </form>
  );
}

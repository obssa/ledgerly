"use client";
import { DateInput } from "@/components/DateInput";

import { Dt } from "@/components/Dt";

import { useActionState } from "react";
import { Card } from "@/components/ui";
import type { InvoiceWriteOff } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { formatAmount } from "@/lib/format";
import { reverseBadDebt, writeOffBadDebt } from "../actions";

/**
 * Module 2: a customer who will not pay. What the invoice still owes, or part of it, is written off as a bad debt
 * (Dr Bad debts, Cr Accounts receivable); nothing goes to the customer. If they pay after all, the write-off is
 * reversed and the invoice is owed again.
 */
export function BadDebtPanel({ invoiceId, writeOffs, canManage, owed, issueDate }: { invoiceId: string; writeOffs: InvoiceWriteOff[]; canManage: boolean; owed: string; issueDate: string }) {
  const [offState, writeOff, writingOff] = useActionState(writeOffBadDebt, undefined);
  const [backState, reverse, reversing] = useActionState(reverseBadDebt, undefined);
  const state = offState ?? backState;
  const standing = writeOffs.filter((w) => w.reversed_on === null);
  const today = todayIn();
  return (
    <Card label="Bad debt">
      <h2 className="text-[17px] font-bold">Bad debt</h2>
      {state?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="mt-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {writeOffs.length === 0 ? (
        <p className="mt-1.5 text-[13px] text-muted">If the customer will not pay, write off what this invoice still owes. It can be reversed if they pay after all.</p>
      ) : (
        <ul className="mt-2 flex flex-col gap-1 text-[13px]">
          {writeOffs.map((w) => (
            <li key={w.id}>
              <span className="num font-semibold">{formatAmount(w.amount)}</span> written off on <span className="num"><Dt v={w.written_off_on} /></span>: {w.reason}
              {w.reversed_on && <>; reversed on <span className="num"><Dt v={w.reversed_on} /></span></>}
            </li>
          ))}
        </ul>
      )}
      {canManage && Number(owed) > 0 && (
        <form action={writeOff} noValidate className="mt-3 flex flex-wrap items-end gap-2">
          <input type="hidden" name="id" value={invoiceId} />
          <div><label className="field-label" htmlFor="bd-amount">Amount</label><input className="field num w-[110px]" id="bd-amount" name="amount" inputMode="decimal" placeholder={formatAmount(owed)} /></div>
          <div><label className="field-label" htmlFor="bd-date">Date</label><DateInput className="field" id="bd-date" name="date" min={issueDate} max={today} defaultValue={today} /></div>
          <div className="min-w-[180px] flex-1"><label className="field-label" htmlFor="bd-reason">Reason</label><input className="field w-full" id="bd-reason" name="reason" required minLength={3} maxLength={500} placeholder="Customer closed the business" /></div>
          <button type="submit" className="btn" disabled={writingOff}>{writingOff ? "Writing off…" : "Write off"}</button>
        </form>
      )}
      {canManage && standing.length > 0 && (
        <form action={reverse} className="mt-3 flex flex-wrap items-end gap-2 border-t border-line pt-3">
          <input type="hidden" name="id" value={invoiceId} />
          <div><label className="field-label" htmlFor="bd-back">Reversed on</label><DateInput className="field" id="bd-back" name="date" min={standing[0].written_off_on} max={today} defaultValue={today} /></div>
          <div className="min-w-[160px] flex-1"><label className="field-label" htmlFor="bd-why">Reason</label><input className="field w-full" id="bd-why" name="reason" maxLength={500} placeholder="Paid after all" /></div>
          <button type="submit" className="btn btn-primary" disabled={reversing}>{reversing ? "Reversing…" : `Reverse ${formatAmount(standing[0].amount)}`}</button>
        </form>
      )}
    </Card>
  );
}

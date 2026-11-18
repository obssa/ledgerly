"use client";

import { useActionState } from "react";
import type { Account, StatementLine } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { importStatement, reviewStatementLine } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

const SOURCE: Record<string, string> = {
  payment: "Payment", payment_made: "Payment made", expense: "Expense", transfer: "Transfer", deposit: "Deposit", refund: "Refund",
  bank_transaction: "From the statement", manual: "Journal entry", invoice: "Invoice", bill: "Bill",
};

/** Import the statement the bank or wallet exported. The file is read and sent as text; it is not kept. */
export function ImportStatement({ accountId }: { accountId: string }) {
  const [state, action, pending] = useActionState(importStatement, undefined);
  return (
    <form action={action} className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="account_id" value={accountId} />
      <div>
        <label className="field-label" htmlFor="file">Statement file (CSV)</label>
        <input className="field" id="file" name="file" type="file" accept=".csv,.txt,text/csv,text/plain" aria-describedby="file-hint" />
        <p id="file-hint" className="mt-1.5 text-[13px] text-muted">
          Any bank or wallet export with a date, a description and an amount (or money out and money in) column. Lines already imported are skipped.
        </p>
      </div>
      <details>
        <summary className="cursor-pointer text-[13px] font-semibold text-brand">Or paste the lines</summary>
        <label className="field-label mt-2" htmlFor="csv">Statement lines, with the header row</label>
        <textarea className="field min-h-[120px] font-mono text-[13px]" id="csv" name="csv" placeholder={"Date,Description,Reference,Debit,Credit,Balance\n05/11/2026,Service charge,,15.00,,14987.45"} />
      </details>
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Importing…" : "Import statement"}</button></div>
    </form>
  );
}

/** What to do with one statement line: match it, categorise it, exclude it, or undo what was done. */
export function ReviewLine({ line, accounts, canReview, foreign }: {
  line: StatementLine; accounts: Account[]; canReview: boolean;
  /** A foreign-currency account: its lines are entered in that currency at the day's rate, or the one given. */
  foreign?: { currency: string; base: string };
}) {
  const [state, action, pending] = useActionState(reviewStatementLine, undefined);
  const hidden = (step: string) => <><input type="hidden" name="line_id" value={line.id} /><input type="hidden" name="step" value={step} /></>;

  if (line.status !== "imported") {
    return (
      <div className="grid gap-1.5">
        <Message state={state} />
        {line.entry && (
          <p className="text-[13px]">
            {line.status === "categorised" ? "Posted as" : "Matched to"} entry <span className="num font-semibold">#{line.entry.number}</span>
            {" "}({SOURCE[line.entry.source_type] ?? line.entry.source_type}, <span className="num">{line.entry.date}</span>){line.entry.memo ? `: ${line.entry.memo}` : ""}
          </p>
        )}
        {line.note && <p className="text-[13px] text-muted">{line.status === "excluded" ? "Excluded: " : ""}{line.note}</p>}
        {canReview && line.status !== "reconciled" && (
          <form action={action} className="flex flex-wrap items-end gap-2">
            {hidden("undo")}
            <div className="min-w-[200px] flex-1">
              <label className="sr-only" htmlFor={`undo-${line.id}`}>Why undo</label>
              <input className="field" id={`undo-${line.id}`} name="reason" placeholder={line.status === "categorised" ? "Why (the entry is reversed)" : "Why (optional)"} maxLength={300} />
            </div>
            <button type="submit" className="btn" disabled={pending}>Undo</button>
          </form>
        )}
      </div>
    );
  }

  if (!canReview) {
    return <p className="text-[13px] text-muted">{line.suggestions?.length ? `${line.suggestions.length} possible match${line.suggestions.length === 1 ? "" : "es"}` : "To review"}</p>;
  }

  return (
    <div className="grid gap-2">
      <Message state={state} />
      {(line.suggestions ?? []).length > 0 ? (
        <form action={action} className="grid gap-1.5">
          {hidden("match")}
          {(line.suggestions ?? []).map((s) => (
            <button key={s.journal_line_id} type="submit" name="journal_line_id" value={s.journal_line_id} className="btn justify-start text-left" disabled={pending}>
              Match to <span className="num">#{s.entry}</span> · {SOURCE[s.source_type] ?? s.source_type} · <span className="num">{s.date}</span>
              {s.contact ? ` · ${s.contact}` : ""}{s.days_apart > 0 ? ` (${s.days_apart} day${s.days_apart === 1 ? "" : "s"} apart)` : ""}
            </button>
          ))}
        </form>
      ) : (
        <p className="text-[13px] text-muted">Nothing in the books for this amount within a week. Categorise it, or record the payment first.</p>
      )}
      <details>
        <summary className="cursor-pointer text-[13px] font-semibold text-brand">Categorise</summary>
        <form action={action} className="mt-2 flex flex-wrap items-end gap-2">
          {hidden("categorise")}
          <div className="min-w-[200px] flex-1">
            <label className="field-label" htmlFor={`account-${line.id}`}>Account</label>
            <select className="field" id={`account-${line.id}`} name="account_id" defaultValue="">
              <option value="" disabled>Choose…</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
            </select>
          </div>
          <div className="min-w-[200px] flex-1">
            <label className="field-label" htmlFor={`memo-${line.id}`}>Memo</label>
            <input className="field" id={`memo-${line.id}`} name="memo" defaultValue={line.description} maxLength={255} />
          </div>
          {foreign && (
            <div className="w-[150px]">
              <label className="field-label" htmlFor={`rate-${line.id}`}>{`Rate (${foreign.base} per ${foreign.currency})`}</label>
              <input className="field num" id={`rate-${line.id}`} name="fx_rate" inputMode="decimal" placeholder="The day's rate" />
            </div>
          )}
          <button type="submit" className="btn btn-primary" disabled={pending}>Post</button>
        </form>
      </details>
      <details>
        <summary className="cursor-pointer text-[13px] font-semibold text-brand">Exclude</summary>
        <form action={action} className="mt-2 flex flex-wrap items-end gap-2">
          {hidden("exclude")}
          <div className="min-w-[200px] flex-1">
            <label className="field-label" htmlFor={`reason-${line.id}`}>Why it stays out of the books</label>
            <input className="field" id={`reason-${line.id}`} name="reason" required minLength={3} maxLength={300} placeholder="Duplicate, another account's line…" />
          </div>
          <button type="submit" className="btn" disabled={pending}>Exclude</button>
        </form>
      </details>
    </div>
  );
}

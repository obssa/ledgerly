"use client";

import { Dt } from "@/components/Dt";

import { useActionState, useState } from "react";
import type { Account, StatementLine } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import Link from "@/components/Link";
import { applyBankRules, importStatement, reviewStatementLine } from "./actions";

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
        <label className="field-label" htmlFor="file">Statement file</label>
        <input className="field" id="file" name="file" type="file" accept=".csv,.xlsx,.pdf,.txt,.ofx,.qfx,.sta,.940,.mt940,.xml,.camt,text/csv,text/plain,application/pdf,application/xml,text/xml,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="file-hint" />
        <p id="file-hint" className="mt-1.5 text-[13px] text-muted">
          CSV or an Excel workbook (any export with a date, a description and an amount, or money out and money in, column), the PDF statement from internet banking
          (not a scan), OFX or QFX, SWIFT MT940, or ISO 20022 camt.053.
          The form is recognised from the file itself. Lines already imported are skipped.
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

/** 077: one statement line over several accounts, in one entry; the parts add up to the line. */
function SplitLine({ line, accounts, action, pending, hidden, foreign }: {
  line: StatementLine; accounts: Account[]; action: (form: FormData) => void; pending: boolean; hidden: React.ReactNode; foreign?: { currency: string; base: string };
}) {
  const [rows, setRows] = useState([0, 1]);
  const [amounts, setAmounts] = useState<Record<number, string>>({});
  const whole = line.amount.replace("-", "");
  const sum = Object.values(amounts).reduce((s, v) => s + (Number(v.replace(/,/g, "")) || 0), 0);
  const left = (Number(whole) - sum).toFixed(2);
  return (
    <details>
      <summary className="cursor-pointer text-[13px] font-semibold text-brand">Split over several accounts</summary>
      <form action={action} className="mt-2 grid gap-2">
        {hidden}
        {rows.map((r, i) => (
          <div key={r} className="flex flex-wrap items-end gap-2">
            <div className="min-w-[180px] flex-1">
              <label className="field-label" htmlFor={`part-account-${line.id}-${r}`}>Account {i + 1}</label>
              <select className="field" id={`part-account-${line.id}-${r}`} name={`part_account_${r}`} defaultValue="">
                <option value="" disabled>Choose…</option>
                {accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
              </select>
            </div>
            <div className="w-[120px]">
              <label className="field-label" htmlFor={`part-amount-${line.id}-${r}`}>Amount</label>
              <input className="field num text-right" id={`part-amount-${line.id}-${r}`} name={`part_amount_${r}`} inputMode="decimal"
                onChange={(e) => setAmounts((m) => ({ ...m, [r]: e.target.value }))} />
            </div>
            <div className="min-w-[160px] flex-1">
              <label className="field-label" htmlFor={`part-memo-${line.id}-${r}`}>Memo</label>
              <input className="field" id={`part-memo-${line.id}-${r}`} name={`part_memo_${r}`} maxLength={255} />
            </div>
            {rows.length > 2 && <button type="button" className="btn !px-3" aria-label={`Remove part ${i + 1}`} onClick={() => setRows((rs) => rs.filter((x) => x !== r))}>×</button>}
          </div>
        ))}
        {foreign && (
          <div className="w-[150px]">
            <label className="field-label" htmlFor={`split-rate-${line.id}`}>{`Rate (${foreign.base} per ${foreign.currency})`}</label>
            <input className="field num" id={`split-rate-${line.id}`} name="fx_rate" inputMode="decimal" placeholder="The day's rate" />
          </div>
        )}
        <p className={`text-[13px] ${left === "0.00" ? "text-good" : "text-muted"}`}>{left === "0.00" ? "The parts add up to the line." : `${left} of ${whole} still to share out.`}</p>
        <div className="flex gap-2">
          <button type="button" className="btn" disabled={rows.length >= 20} onClick={() => setRows((rs) => [...rs, Math.max(...rs) + 1])}>+ Add a part</button>
          <button type="submit" className="btn btn-primary" disabled={pending}>Post the split</button>
        </div>
      </form>
    </details>
  );
}

/** 076: categorise every line to review that a bank rule fits. */
export function ApplyRules({ accountId }: { accountId: string }) {
  const [state, action, pending] = useActionState(applyBankRules, undefined);
  return (
    <form action={action} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="account_id" value={accountId} />
      <button type="submit" className="btn" disabled={pending}>{pending ? "Applying…" : "Apply the rules"}</button>
      <Link href="/banking/rules" className="text-[13px] text-brand underline">Bank rules</Link>
      <Message state={state} />
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
            {" "}({SOURCE[line.entry.source_type] ?? line.entry.source_type}, <span className="num"><Dt v={line.entry.date} /></span>){line.entry.memo ? `: ${line.entry.memo}` : ""}
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
      {line.rule && (
        // 076: the bank rule that fits, one press to categorise by it.
        <form action={action} className="flex flex-wrap items-center gap-2 rounded-lg bg-brand-tint px-2 py-1.5 text-[13px]">
          {hidden("categorise")}
          <input type="hidden" name="account_id" value={line.rule.account.id} />
          {line.rule.contact && <input type="hidden" name="contact_id" value={line.rule.contact.id} />}
          {line.rule.memo && <input type="hidden" name="memo" value={line.rule.memo} />}
          <span className="min-w-0 flex-1">Rule <span className="font-semibold">{line.rule.name}</span>: to <span className="num">{line.rule.account.code}</span> {line.rule.account.name}{line.rule.contact ? ` · ${line.rule.contact.name}` : ""}</span>
          <button type="submit" className="btn !py-1" disabled={pending}>Categorise by the rule</button>
        </form>
      )}
      {(line.suggestions ?? []).length > 0 ? (
        <form action={action} className="grid gap-1.5">
          {hidden("match")}
          {(line.suggestions ?? []).map((s) => (
            <button key={s.journal_line_id} type="submit" name="journal_line_id" value={s.journal_line_id} className="btn justify-start text-left" disabled={pending}>
              Match to <span className="num">#{s.entry}</span> · {SOURCE[s.source_type] ?? s.source_type} · <span className="num"><Dt v={s.date} /></span>
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
        {!line.rule && (
          <Link href={`/banking/rules?contains=${encodeURIComponent(line.description.slice(0, 120))}&account=${encodeURIComponent(line.account_id)}&direction=${line.amount.startsWith("-") ? "out" : "in"}`}
            className="mt-1 inline-block text-[13px] text-brand underline">Make a rule for lines like this</Link>
        )}
      </details>
      <SplitLine line={line} accounts={accounts} action={action} pending={pending} hidden={hidden("split")} foreign={foreign} />
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

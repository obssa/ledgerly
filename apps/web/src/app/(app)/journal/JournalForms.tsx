"use client";

import { useActionState, useState } from "react";
import type { Account, Dimension } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { emptyLine, journalTotals, type JournalLineInput } from "@/lib/journal";
import { postJournal, reverseJournal } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-3 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

const KINDS = [["location", "location_id", "Location"], ["class", "class_id", "Class"], ["project", "project_id", "Project"]] as const;

/** A manual journal: date, memo, and balanced lines; dimensions per line when the company uses them. */
export function NewJournal({ accounts, dimensions }: { accounts: Account[]; dimensions: Dimension[] }) {
  const [state, action, pending] = useActionState(postJournal, undefined);
  const today = new Date().toISOString().slice(0, 10);
  const [lines, setLines] = useState<JournalLineInput[]>([emptyLine(), emptyLine()]);
  const totals = journalTotals(lines);
  const used = KINDS.filter(([kind]) => dimensions.some((d) => d.kind === kind));
  const set = (i: number, patch: Partial<JournalLineInput>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  const bad = (n: string) => Boolean(state?.fields?.[n]);

  return (
    <form action={action} noValidate className="grid gap-4">
      <Message state={state} />
      <input type="hidden" name="lines" value={JSON.stringify(lines)} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
        <div>
          <label className="field-label" htmlFor="date">Date</label>
          <input className={`field ${bad("date") ? "!border-bad" : ""}`} id="date" name="date" type="date" max={today} defaultValue={today} />
        </div>
        <div className="col-span-2 min-w-0">
          <label className="field-label" htmlFor="memo">Memo</label>
          <input className={`field ${bad("memo") ? "!border-bad" : ""}`} id="memo" name="memo" maxLength={500} placeholder="What the entry is for, such as November rent accrued" />
        </div>
      </div>
      <div className="overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr>
              <th scope="col" className="min-w-[220px]">Account</th><th scope="col">Description</th>
              {used.map(([, , label]) => <th key={label} scope="col">{label}</th>)}
              <th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th><th scope="col"><span className="sr-only">Remove</span></th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l, i) => (
              <tr key={i}>
                <td>
                  <label className="sr-only" htmlFor={`account-${i}`}>Account, line {i + 1}</label>
                  <select className={`field ${bad(`lines.${i}.account_id`) ? "!border-bad" : ""}`} id={`account-${i}`} value={l.account_id} onChange={(e) => set(i, { account_id: e.target.value })}>
                    <option value="">Choose…</option>
                    {accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
                  </select>
                </td>
                <td>
                  <label className="sr-only" htmlFor={`desc-${i}`}>Description, line {i + 1}</label>
                  <input className="field" id={`desc-${i}`} value={l.description} maxLength={255} onChange={(e) => set(i, { description: e.target.value })} />
                </td>
                {used.map(([kind, column, label]) => (
                  <td key={kind}>
                    <label className="sr-only" htmlFor={`${kind}-${i}`}>{label}, line {i + 1}</label>
                    <select className="field" id={`${kind}-${i}`} value={l[column] ?? ""} onChange={(e) => set(i, { [column]: e.target.value || undefined })}>
                      <option value="">—</option>
                      {dimensions.filter((d) => d.kind === kind).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                  </td>
                ))}
                <td>
                  <label className="sr-only" htmlFor={`debit-${i}`}>Debit, line {i + 1}</label>
                  <input className={`field num text-right ${bad(`lines.${i}.debit`) ? "!border-bad" : ""}`} id={`debit-${i}`} inputMode="decimal" value={l.debit}
                    onChange={(e) => set(i, { debit: e.target.value, credit: e.target.value ? "" : l.credit })} />
                </td>
                <td>
                  <label className="sr-only" htmlFor={`credit-${i}`}>Credit, line {i + 1}</label>
                  <input className="field num text-right" id={`credit-${i}`} inputMode="decimal" value={l.credit}
                    onChange={(e) => set(i, { credit: e.target.value, debit: e.target.value ? "" : l.debit })} />
                </td>
                <td>{lines.length > 2 && <button type="button" className="btn" onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label={`Remove line ${i + 1}`}>×</button>}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-ink font-bold">
              <td colSpan={2 + used.length}>{totals.balanced ? "Balanced" : totals.difference === "0.00" ? "Enter the amounts" : `Out by ${totals.difference.replace("-", "")}`}</td>
              <td className="num text-right">{totals.debit}</td><td className="num text-right">{totals.credit}</td><td />
            </tr>
          </tfoot>
        </table>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" className="btn" onClick={() => setLines([...lines, emptyLine()])}>Add a line</button>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Posting…" : "Post entry"}</button>
        <span className="text-[13px] text-muted">A posted entry is never edited: to correct it, reverse it and post again.</span>
      </div>
    </form>
  );
}

export function ReverseJournal({ id, date }: { id: string; date: string }) {
  const [state, action, pending] = useActionState(reverseJournal, undefined);
  const today = new Date().toISOString().slice(0, 10);
  return (
    <form action={action} noValidate className="flex flex-wrap items-end gap-2">
      <div className="w-full"><Message state={state} /></div>
      <input type="hidden" name="id" value={id} />
      <div>
        <label className="field-label" htmlFor="reverse_date">Reverse on</label>
        <input className="field" id="reverse_date" name="date" type="date" min={date} max={today} defaultValue={today} />
      </div>
      <div className="min-w-[260px] flex-1">
        <label className="field-label" htmlFor="reverse_reason">Why</label>
        <input className="field" id="reverse_reason" name="reason" maxLength={500} />
      </div>
      <button type="submit" className="btn" disabled={pending}>{pending ? "Reversing…" : "Reverse entry"}</button>
    </form>
  );
}

"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { billTime, logTime, removeTime } from "./actions";

type Option = { id: string; code: string; name: string };

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** Log hours against a project: the day, how long, billable or not, at a rate of their own or the project's. */
export function LogTime({ projects, projectId }: { projects: Option[]; projectId?: string }) {
  const [state, action, pending] = useActionState(logTime, undefined);
  const today = todayIn();
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div className="min-w-[200px]">
          <label className="field-label" htmlFor="project_id">Project</label>
          <select className={`field ${bad("project_id") ? "!border-bad" : ""}`} id="project_id" name="project_id" defaultValue={projectId ?? ""}>
            <option value="">Choose…</option>
            {projects.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}
          </select>
        </div>
        <div><label className="field-label" htmlFor="work_date">Day</label><DateInput className={`field ${bad("work_date") ? "!border-bad" : ""}`} id="work_date" name="work_date" max={today} defaultValue={today} /></div>
        <div><label className="field-label" htmlFor="hours">Hours</label><input className={`field num w-[90px] ${bad("hours") ? "!border-bad" : ""}`} id="hours" name="hours" inputMode="decimal" placeholder="2.5" /></div>
        <div><label className="field-label" htmlFor="rate">Rate (optional)</label><input className={`field num w-[110px] ${bad("rate") ? "!border-bad" : ""}`} id="rate" name="rate" inputMode="decimal" /></div>
        <label className="flex items-center gap-2 pb-2 text-[14px]"><input type="checkbox" name="billable" defaultChecked /> Billable</label>
        <div className="min-w-[200px] flex-1"><label className="field-label" htmlFor="note">What was done</label><input className="field" id="note" name="note" maxLength={500} /></div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Logging…" : "Log hours"}</button>
      </div>
    </form>
  );
}

export function RemoveTime({ id }: { id: string }) {
  const [state, action, pending] = useActionState(removeTime, undefined);
  return (
    <form action={action} className="inline">
      <input type="hidden" name="id" value={id} />
      <button type="submit" className="btn !px-2 !py-0.5 text-[12px]" disabled={pending} aria-label="Remove these hours">Remove</button>
      {state?.error && <span role="alert" className="ml-1 text-[12px] text-bad">{state.error}</span>}
    </form>
  );
}

/** Bill the billable, unbilled hours (up to a day, if given) on a draft invoice for the project's customer. */
export function BillTime({ projectId, incomeAccounts }: { projectId: string; incomeAccounts: Option[] }) {
  const [state, action, pending] = useActionState(billTime, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="project_id" value={projectId} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="issue_date">Invoice date</label><DateInput className="field" id="issue_date" name="issue_date" max={today} defaultValue={today} /></div>
        <div><label className="field-label" htmlFor="up_to">Hours up to (optional)</label><DateInput className="field" id="up_to" name="up_to" /></div>
        <div className="min-w-[200px]">
          <label className="field-label" htmlFor="income_account_id">Income account</label>
          <select className="field" id="income_account_id" name="income_account_id">{incomeAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
        </div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Billing…" : "Bill the hours"}</button>
      </div>
      <p className="text-[13px] text-muted">A draft invoice is made, one line per entry; check it and post it as usual. Voiding it frees the hours again.</p>
    </form>
  );
}

"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import type { ProjectMilestone } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import { addMilestone, billMilestone, removeMilestone } from "./actions";

type Option = { id: string; code: string; name: string };

const Message = ({ state }: { state?: { error?: string; notice?: string } }) => (
  <>
    {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
    {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
  </>
);

/** A part of the contract: an amount, or a share of the contract value (progress billing). */
export function AddMilestone({ projectId, hasContractValue }: { projectId: string; hasContractValue: boolean }) {
  const [state, action, pending] = useActionState(addMilestone, undefined);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="project_id" value={projectId} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div className="min-w-[200px] flex-1"><label className="field-label" htmlFor="m-name">Milestone</label>
          <input className="field" id="m-name" name="name" maxLength={200} placeholder="For example: Foundations complete" /></div>
        <div><label className="field-label" htmlFor="m-amount">Amount</label><input className="field num w-[140px]" id="m-amount" name="amount" inputMode="decimal" /></div>
        {hasContractValue && (
          <div><label className="field-label" htmlFor="m-percent">or % of contract</label><input className="field num w-[110px]" id="m-percent" name="percent" inputMode="decimal" /></div>
        )}
        <div><label className="field-label" htmlFor="m-due">Due</label><DateInput className="field" id="m-due" name="due_on" /></div>
        <button type="submit" className="btn" disabled={pending}>{pending ? "Adding…" : "Add milestone"}</button>
      </div>
    </form>
  );
}

/** Bill a planned milestone (a draft invoice to the customer), or remove it. */
export function MilestoneControls({ projectId, milestone, incomeAccounts, canBill }: { projectId: string; milestone: ProjectMilestone; incomeAccounts: Option[]; canBill: boolean }) {
  const [billState, bill, billing] = useActionState(billMilestone, undefined);
  const [removeState, remove, removing] = useActionState(removeMilestone, undefined);
  if (milestone.status !== "planned") return null;
  const today = todayIn();
  return (
    <div className="flex flex-col items-end gap-2">
      <Message state={billState ?? removeState} />
      <div className="flex flex-wrap items-end justify-end gap-2">
        {canBill && incomeAccounts.length > 0 && (
          <form action={bill} className="flex flex-wrap items-end gap-2">
            <input type="hidden" name="project_id" value={projectId} />
            <input type="hidden" name="milestone_id" value={milestone.id} />
            <label className="sr-only" htmlFor={`bd-${milestone.id}`}>Invoice date for {milestone.name}</label>
            <DateInput className="field !h-9 !w-auto" id={`bd-${milestone.id}`} name="issue_date" max={today} defaultValue={today} />
            <label className="sr-only" htmlFor={`ba-${milestone.id}`}>Income account</label>
            <select className="field !h-9 !w-auto" id={`ba-${milestone.id}`} name="income_account_id">
              {incomeAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
            </select>
            <button type="submit" className="btn btn-primary !py-1 text-[13px]" disabled={billing}>{billing ? "Billing…" : "Bill"}</button>
          </form>
        )}
        <form action={remove}>
          <input type="hidden" name="project_id" value={projectId} />
          <input type="hidden" name="milestone_id" value={milestone.id} />
          <button type="submit" className="btn !py-1 text-[13px] text-bad" disabled={removing}>Remove</button>
        </form>
      </div>
    </div>
  );
}

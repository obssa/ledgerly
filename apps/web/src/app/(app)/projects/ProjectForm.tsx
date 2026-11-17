"use client";

import { useActionState } from "react";
import type { Project } from "@/lib/api";
import { PROJECT_BILLING, PROJECT_STATUSES } from "@/lib/projects";
import { saveProject } from "./actions";

/** Add a project, or edit one's details, status and budget (its code stays). */
export function ProjectForm({ project, customers }: { project?: Project; customers: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(saveProject, undefined);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const field = (name: string, label: string, opts: { type?: string; value?: string | null; hint?: string } = {}) => (
    <div className="min-w-0">
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${bad(name) ? "!border-bad" : ""}`} id={name} name={name} type={opts.type ?? "text"} defaultValue={opts.value ?? ""}
        inputMode={opts.type === undefined && name.match(/value|budget/) ? "decimal" : undefined} />
      {opts.hint && <p className="mt-1 text-[12px] text-muted">{opts.hint}</p>}
    </div>
  );
  return (
    <form action={action} noValidate className="grid gap-4">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {project && <input type="hidden" name="id" value={project.id} />}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
        {!project && field("code", "Code", { hint: "Short and unique, such as P-014. It never changes." })}
        {field("name", "Name", { value: project?.name })}
        <div>
          <label className="field-label" htmlFor="customer_id">Customer</label>
          <select className={`field ${bad("customer_id") ? "!border-bad" : ""}`} id="customer_id" name="customer_id" defaultValue={project?.customer?.id ?? ""}>
            <option value="">None (internal)</option>
            {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label" htmlFor="status">Status</label>
          <select className="field" id="status" name="status" defaultValue={project?.status ?? "active"}>{PROJECT_STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
        <div>
          <label className="field-label" htmlFor="billing">Billed</label>
          <select className="field" id="billing" name="billing" defaultValue={project?.billing ?? "fixed_fee"}>{PROJECT_BILLING.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
        {field("starts_on", "Starts", { type: "date", value: project?.starts_on })}
        {field("ends_on", "Ends", { type: "date", value: project?.ends_on })}
        {field("contract_value", "Contract value", { value: project?.contract_value })}
        {field("budget_income", "Income budget", { value: project?.budget_income })}
        {field("budget_cost", "Cost budget", { value: project?.budget_cost })}
      </div>
      <div>
        <label className="field-label" htmlFor="notes">Notes</label>
        <textarea className="field min-h-[70px]" id="notes" name="notes" maxLength={2000} defaultValue={project?.notes ?? ""} />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : project ? "Save changes" : "Add project"}</button>
        <span className="text-[13px] text-muted">A completed or cancelled project takes no new lines; what is already tagged stays.</span>
      </div>
    </form>
  );
}

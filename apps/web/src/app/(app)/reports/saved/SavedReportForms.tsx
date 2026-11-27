"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState, useState } from "react";
import { PERIODS, SAVEABLE, SCHEDULES } from "@/lib/saved-reports";
import { removeSavedReport, saveReport } from "./actions";

type Initial = { report?: string; range?: string; from?: string; to?: string; options: Record<string, string> };

/** Save a report: its name, period (rolling or custom dates) and whether it is emailed to you weekly or monthly. */
export function SavedReportForm({ initial }: { initial: Initial }) {
  const [state, action, pending] = useActionState(saveReport, undefined);
  const [report, setReport] = useState(initial.report && SAVEABLE[initial.report] ? initial.report : "profit-and-loss");
  // Saved from a report page: its preset ("Last fiscal year"), or its own dates; otherwise last month.
  const [period, setPeriod] = useState(initial.range && PERIODS.some(([v]) => v === initial.range) && initial.range !== "custom" ? initial.range : initial.to ? "custom" : "last_month");
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const atADate = Boolean(SAVEABLE[report]?.atADate);
  return (
    <form action={action} noValidate className="grid gap-3">
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
      {Object.entries(initial.options).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3">
        <div>
          <label className="field-label" htmlFor="name">Name</label>
          <input className={`field ${bad("name") ? "!border-bad" : ""}`} id="name" name="name" maxLength={80} defaultValue={initial.report ? SAVEABLE[initial.report]?.label ?? "" : ""} />
        </div>
        <div>
          <label className="field-label" htmlFor="report">Report</label>
          <select className={`field ${bad("report") ? "!border-bad" : ""}`} id="report" name="report" value={report} onChange={(e) => setReport(e.target.value)}>
            {Object.entries(SAVEABLE).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label" htmlFor="period">{atADate ? "At the end of" : "Period"}</label>
          <select className="field" id="period" name="period" value={period} onChange={(e) => setPeriod(e.target.value)}>
            {PERIODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        {period === "custom" && !atADate && (
          <div><label className="field-label" htmlFor="from">From</label>
            <DateInput className={`field ${bad("from") ? "!border-bad" : ""}`} id="from" name="from" defaultValue={initial.from} /></div>
        )}
        {period === "custom" && (
          <div><label className="field-label" htmlFor="to">{atADate ? "As at" : "To"}</label>
            <DateInput className={`field ${bad("to") ? "!border-bad" : ""}`} id="to" name="to" defaultValue={initial.to} /></div>
        )}
        <div>
          <label className="field-label" htmlFor="schedule">Email it to me</label>
          <select className="field" id="schedule" name="schedule" defaultValue="none">{SCHEDULES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
      </div>
      {Object.keys(initial.options).length > 0 && (
        <p className="text-[13px] text-muted">With the options shown on the report: {Object.entries(initial.options).map(([k, v]) => `${k.replace(/_id$/, "").replace(/_/g, " ")} ${v.length > 20 ? "chosen" : v}`).join(", ")}.</p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save report"}</button>
        <span className="text-[13px] text-muted">A rolling period moves with the calendar. The email links to the report; it carries no figures.</span>
      </div>
    </form>
  );
}

export function RemoveSavedReport({ id, name }: { id: string; name: string }) {
  const [state, action, pending] = useActionState(removeSavedReport, undefined);
  return (
    <form action={action} className="inline">
      <input type="hidden" name="id" value={id} />
      <button type="submit" className="text-[13px] font-semibold text-bad hover:underline" disabled={pending} aria-label={`Remove ${name}`}>{pending ? "Removing…" : "Remove"}</button>
      {state?.error && <span role="alert" className="ml-2 text-[12px] text-bad">{state.error}</span>}
    </form>
  );
}

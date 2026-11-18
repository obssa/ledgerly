"use client";

import { useActionState } from "react";
import { importSummary, type ImportKind } from "@/lib/imports";
import { runImport } from "./actions";

/** A CSV file (or pasted lines): Check runs every row and keeps nothing; Import adds them all, or none. */
export function ImportForm({ kind, example }: { kind: ImportKind; example: string }) {
  const [state, action, pending] = useActionState(runImport, undefined);
  const r = state?.result;
  return (
    <form action={action} className="grid gap-3">
      <input type="hidden" name="kind" value={kind} />
      {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
      {r && (
        <p role="status" className={`rounded-lg px-3 py-2 text-[13px] font-semibold ${r.errors.length ? "bg-warn-tint text-warn" : "bg-good-tint text-good"}`}>
          {importSummary(r)}
          {r.ignored_columns.length > 0 && <span className="block font-normal">Columns not read: {r.ignored_columns.join(", ")}.</span>}
        </p>
      )}
      {r && r.errors.length > 0 && (
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Row</th><th scope="col">Name</th><th scope="col">What to fix</th></tr></thead>
            <tbody>{r.errors.map((e) => <tr key={e.row}><td className="num">{e.row}</td><td>{e.name ?? ""}</td><td className="text-[13px]">{e.messages.join(" ")}</td></tr>)}</tbody>
          </table>
        </div>
      )}
      <div>
        <label className="field-label" htmlFor="file">CSV file</label>
        <input className="field" id="file" name="file" type="file" accept=".csv,.txt,text/csv,text/plain" />
      </div>
      <div>
        <label className="field-label" htmlFor="csv">Or paste the lines (the first line names the columns)</label>
        <textarea className="field num min-h-[120px] text-[12px]" id="csv" name="csv" placeholder={example} />
      </div>
      <div className="flex flex-wrap gap-2">
        <button type="submit" name="mode" value="check" className="btn" disabled={pending}>{pending ? "Working…" : "Check"}</button>
        <button type="submit" name="mode" value="import" className="btn btn-primary" disabled={pending}>Import</button>
      </div>
    </form>
  );
}

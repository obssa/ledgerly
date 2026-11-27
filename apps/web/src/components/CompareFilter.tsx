import { DateInput } from "./DateInput";
import { AS_OF_QUICK, COMPARE_AS_OF_PRESETS, COMPARE_RANGE_PRESETS, DIFFS, RANGE_QUICK, type Diff, type Slot } from "@/lib/compare";

/**
 * "Compare with": up to three other periods (or dates) beside the report's own, each a quick choice (previous period,
 * same period last year …), a preset or dates of the reader's own, and the change columns. Part of the report's
 * filter form (a plain GET form: it works without JavaScript); custom dates are typed the company's way.
 */
export function CompareFilter({ slots, diff, asOf = false, open }: { slots: Slot[]; diff: Diff; asOf?: boolean; open?: boolean }) {
  const quick = asOf ? AS_OF_QUICK : RANGE_QUICK;
  const presets = (asOf ? COMPARE_AS_OF_PRESETS : COMPARE_RANGE_PRESETS).filter((p) => p.key !== "custom");
  const groups = [...new Set(presets.map((p) => ("group" in p ? (p.group as string) : "")))];
  const set = slots.filter((s) => s.kind && s.kind !== "none").length;
  return (
    <details className="w-full rounded-xl border border-line bg-surface px-3 py-2" open={open ?? set > 0}>
      <summary className="cursor-pointer text-[13.5px] font-semibold">Compare with{set > 0 ? ` · ${set} period${set === 1 ? "" : "s"}` : ""}</summary>
      <div className="mt-3 flex flex-col gap-2.5">
        {slots.map((s, i) => {
          const n = i + 1;
          return (
            <div key={n} className="flex flex-wrap items-end gap-2.5">
              <div>
                <label className="field-label" htmlFor={`c${n}`}>{n === 1 ? "Compare with" : `and with (${n})`}</label>
                <select className="field" id={`c${n}`} name={`c${n}`} defaultValue={s.kind === "none" ? "" : s.kind}>
                  <option value="">{n === 1 ? "No comparison" : "—"}</option>
                  <optgroup label="Quick">{quick.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}</optgroup>
                  {groups.map((g) => (
                    <optgroup key={g || "presets"} label={g || "Presets"}>
                      {presets.filter((p) => ("group" in p ? p.group : "") === g).map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
                    </optgroup>
                  ))}
                  <option value="custom">Custom {asOf ? "date" : "dates"}</option>
                </select>
              </div>
              {asOf ? (
                <div><label className="field-label" htmlFor={`c${n}_as_of`}>Date (custom)</label>
                  <DateInput plain className="field !w-[150px]" id={`c${n}_as_of`} name={`c${n}_as_of`} defaultValue={s.kind === "custom" ? s.asOf : ""} /></div>
              ) : (
                <>
                  <div><label className="field-label" htmlFor={`c${n}_from`}>From (custom)</label>
                    <DateInput plain className="field !w-[150px]" id={`c${n}_from`} name={`c${n}_from`} defaultValue={s.kind === "custom" ? s.from : ""} /></div>
                  <div><label className="field-label" htmlFor={`c${n}_to`}>To (custom)</label>
                    <DateInput plain className="field !w-[150px]" id={`c${n}_to`} name={`c${n}_to`} defaultValue={s.kind === "custom" ? s.to : ""} /></div>
                </>
              )}
            </div>
          );
        })}
        <div>
          <label className="field-label" htmlFor="diff">Change columns (against the first comparison)</label>
          <select className="field" id="diff" name="diff" defaultValue={diff}>{DIFFS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
      </div>
    </details>
  );
}

"use client";

import { useId, useRef, useState } from "react";
import { DATE_PREFS_FIELD, TYPED, formatDate, parseDate } from "@/lib/dates";
import { daysInMonth, ETHIOPIAN_MONTHS, fromGregorian, toGregorian } from "@/lib/ethiopian";
import { useDates } from "./DatePrefs";

/*
 * A date field written the company's way (Settings, Date format): 27/11/2026, 11/27/2026 or 2026-11-27, and the
 * Ethiopian calendar's own numbers for a company on it, whatever the browser's language. Typed, or picked from a
 * calendar (the browser's own for Gregorian, a month grid for Ethiopian).
 *
 * The form sends the stored "YYYY-MM-DD" under the field's name (a hidden input) and what was typed beside it, which
 * the actions read through text() (lib/forms.ts): what was typed counts, with or without JavaScript, and a date that
 * cannot be read goes on as typed, for the API to refuse with its message, never an old date in its place. `plain` sends the typed text itself (the report filters, plain GET forms that work without
 * JavaScript, read it with parseDate on the server).
 */
export interface DateInputProps {
  name?: string;
  id?: string;
  className?: string;
  /** Stored dates ("YYYY-MM-DD"). */
  defaultValue?: string | null;
  value?: string | null;
  min?: string;
  max?: string;
  required?: boolean;
  disabled?: boolean;
  plain?: boolean;
  /** A date that can be read was typed or picked ("YYYY-MM-DD"), or the field was emptied (""). */
  onValue?: (iso: string) => void;
  "aria-label"?: string;
  "aria-describedby"?: string;
  "aria-invalid"?: boolean;
}

export function DateInput({ name, id, className = "field", defaultValue, value, min, max, required, disabled, plain, onValue, ...aria }: DateInputProps) {
  const { prefs } = useDates();
  const auto = useId();
  const fieldId = id ?? auto;
  const controlled = value !== undefined;
  const [own, setOwn] = useState(defaultValue ?? "");
  const iso = controlled ? (value ?? "") : own;
  const [text, setText] = useState(() => formatDate(iso, prefs, ""));
  const [problem, setProblem] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const picker = useRef<HTMLInputElement>(null);

  // A new value from the form (another field worked it out): show it, unless it is what is being typed.
  const [shown, setShown] = useState(value);
  if (controlled && value !== shown) {
    setShown(value);
    if (parseDate(text, prefs) !== (value ?? "")) setText(formatDate(value ?? "", prefs, ""));
  }

  const outOfRange = (d: string) =>
    min && d < min ? `Not before ${formatDate(min, prefs)}` : max && d > max ? `Not after ${formatDate(max, prefs)}` : null;

  const choose = (d: string) => {
    if (!controlled) setOwn(d);
    setText(formatDate(d, prefs, ""));
    setProblem(d ? outOfRange(d) : null);
    onValue?.(d);
  };

  const typed = (t: string) => {
    setText(t);
    const d = parseDate(t, prefs);
    if (t.trim() === "") { choose(""); setText(""); return; }
    if (d) { if (!controlled) setOwn(d); onValue?.(d); }
  };

  const settle = () => {
    if (text.trim() === "") { setProblem(required ? "Enter a date" : null); return; }
    const d = parseDate(text, prefs);
    if (!d) { setProblem(`Enter the date as ${prefs.dateFormat}`); return; }
    choose(d);
  };

  const readable = parseDate(text, prefs);
  // What the form sends: the stored date, or what was typed when it cannot be read (the API refuses it with a message).
  const sent = text.trim() === "" ? "" : readable ?? text;
  const errorId = `${fieldId}-problem`;

  return (
    <span className="relative inline-flex w-full flex-col">
      <span className="relative flex">
        <input
          type="text"
          inputMode="numeric"
          autoComplete="off"
          className={`${className} pr-9 ${problem ? "!border-bad" : ""}`}
          id={fieldId}
          name={plain ? name : name ? `${name}${TYPED}` : undefined}
          value={text}
          placeholder={prefs.dateFormat}
          required={required}
          disabled={disabled}
          onChange={(e) => typed(e.target.value)}
          onBlur={settle}
          aria-label={aria["aria-label"]}
          aria-invalid={aria["aria-invalid"] || Boolean(problem) || undefined}
          aria-describedby={[aria["aria-describedby"], problem ? errorId : null].filter(Boolean).join(" ") || undefined}
        />
        <button type="button" disabled={disabled} tabIndex={-1} aria-label="Pick a date" title="Pick a date"
          className="absolute inset-y-0 right-0 grid w-9 place-items-center text-muted hover:text-ink disabled:opacity-40"
          onClick={() => {
            if (prefs.calendar === "ethiopian") { setOpen((o) => !o); return; }
            const p = picker.current;
            if (!p) return;
            try { p.showPicker(); } catch { p.focus(); p.click(); }
          }}>
          <svg aria-hidden="true" viewBox="0 0 20 20" className="size-4" fill="none" stroke="currentColor" strokeWidth="1.6">
            <rect x="3" y="4.5" width="14" height="12.5" rx="2" /><path d="M3 8.5h14M7 2.5v4M13 2.5v4" />
          </svg>
        </button>
        {prefs.calendar === "gregorian" && (
          <input ref={picker} type="date" tabIndex={-1} aria-hidden="true" className="pointer-events-none absolute bottom-0 right-0 h-0 w-0 opacity-0"
            value={readable ?? ""} min={min} max={max} onChange={(e) => choose(e.target.value)} />
        )}
      </span>
      {/* The stored date under the field's own name; with what was typed and how to read it (lib/forms.ts text()), so the
          typed date counts even before the page's JavaScript has loaded. */}
      {!plain && name && <>
        <input type="hidden" name={name} value={sent} />
        <input type="hidden" name={DATE_PREFS_FIELD} value={`${prefs.dateFormat}|${prefs.calendar}`} />
      </>}
      {problem && <span id={errorId} className="mt-1 text-[12.5px] font-semibold text-bad">{problem}</span>}
      {open && prefs.calendar === "ethiopian" && (
        <EthiopianMonth selected={readable} min={min} max={max} onPick={(d) => { choose(d); setOpen(false); }} onClose={() => setOpen(false)} />
      )}
    </span>
  );
}

/** A month of the Ethiopian calendar (Meskerem … Pagume) to pick a day from. */
function EthiopianMonth({ selected, min, max, onPick, onClose }: { selected: string | null; min?: string; max?: string; onPick: (iso: string) => void; onClose: () => void }) {
  const start = fromGregorian(selected ?? new Date().toISOString().slice(0, 10));
  const [view, setView] = useState({ y: start.year, m: start.month });
  const shift = (k: number) => setView((v) => {
    const n = (v.m - 1 + k + 13 * 10) % 13;
    const years = Math.floor((v.m - 1 + k) / 13);
    return { y: v.y + years, m: n + 1 };
  });
  const days = Array.from({ length: daysInMonth(view.y, view.m) }, (_, i) => i + 1);
  return (
    <div role="dialog" aria-label="Pick a date" className="absolute top-full z-30 mt-1 w-[264px] rounded-xl border border-line bg-surface p-2 shadow-lg"
      onKeyDown={(e) => { if (e.key === "Escape") onClose(); }}>
      <div className="mb-2 flex items-center justify-between">
        <button type="button" className="btn !h-8 !px-2" aria-label="Previous month" onClick={() => shift(-1)}>‹</button>
        <span className="text-[13.5px] font-bold">{ETHIOPIAN_MONTHS[view.m - 1]} {view.y}</span>
        <button type="button" className="btn !h-8 !px-2" aria-label="Next month" onClick={() => shift(1)}>›</button>
      </div>
      <div className="grid grid-cols-6 gap-1">
        {days.map((d) => {
          const iso = toGregorian(view.y, view.m, d);
          const off = Boolean((min && iso < min) || (max && iso > max));
          return (
            <button key={d} type="button" disabled={off} onClick={() => onPick(iso)}
              className={`num h-8 rounded-md text-[13px] ${iso === selected ? "bg-brand font-bold text-white" : "hover:bg-canvas"} disabled:opacity-30`}>{d}</button>
          );
        })}
      </div>
    </div>
  );
}

import { formatDate, parseDate } from "./dates";
import { parse, percentChange, toString } from "./decimal";
import { CLOSED_RANGE_PRESETS, RANGE_PRESETS, AS_OF_PRESETS, addDays, resolveAsOf, resolveRange, shiftDay, wholePeriodLabel, type PeriodContext, type RangePreset, type AsOfPreset } from "./periods";
import { previousPeriod, samePeriodLastYear } from "./reports";

/*
 * Comparing a report with up to three other periods, as QuickBooks' "Compare another period" does, and further: each
 * comparison is the previous period, the same period last year or two years ago, any preset (Last quarter, Last
 * fiscal year …) or dates of the reader's own. The report's own period comes first; "Change" and "% change" are
 * against the first comparison. Everything is in the URL (c1, c1_from, c1_to … c3; diff), so the report is saved,
 * printed and exported as shown. Dates may be typed the company's way (27/11/2026); presets roll with today.
 */

export const MAX_COMPARISONS = 3;
const SLOTS = [1, 2, 3] as const;

/** Quick choices for a report over a period. */
export const RANGE_QUICK = [
  { key: "previous_period", label: "Previous period" },
  { key: "previous_year", label: "Same period last year" },
  { key: "two_years_ago", label: "Same period two years ago" },
] as const;

/** Quick choices for a report at a date. */
export const AS_OF_QUICK = [
  { key: "previous_month", label: "A month earlier" },
  { key: "previous_quarter", label: "A quarter earlier" },
  { key: "previous_year", label: "A year earlier" },
  { key: "two_years_ago", label: "Two years earlier" },
] as const;

/** The presets a comparison may be: closed periods (both ends), and Custom. */
export const COMPARE_RANGE_PRESETS = RANGE_PRESETS.filter((p) => CLOSED_RANGE_PRESETS.includes(p.key) || p.key === "custom");
export const COMPARE_AS_OF_PRESETS = AS_OF_PRESETS.filter((p) => p.key !== "today");

export type Diff = "none" | "amount" | "pct" | "both";
export const DIFFS: [Diff, string][] = [["none", "No change columns"], ["amount", "Change (amount)"], ["pct", "% change"], ["both", "Change and % change"]];

/** The URL fields of the comparisons (for filter forms, saved reports and exports). */
export const COMPARE_FIELDS = ["diff", ...SLOTS.flatMap((n) => [`c${n}`, `c${n}_from`, `c${n}_to`, `c${n}_as_of`])];

type Query = Record<string, string | string[] | undefined>;
const one = (q: Query, k: string) => (typeof q[k] === "string" ? (q[k] as string) : undefined);

/** What a slot is set to, as the form shows it. */
export interface Slot { kind: string; from: string | null; to: string | null; asOf: string | null }
/** A period compared with. */
export interface Comparison { label: string; from: string; to: string }
export interface AsOfComparison { label: string; asOf: string }

const prefsOf = (ctx: PeriodContext) => ({ calendar: ctx.calendar, dateFormat: ctx.dateFormat ?? "DD/MM/YYYY" } as const);

/** A column header for a period: "Oct 2026", "Q3 2026", "2025", or its dates the company's way. */
export function periodColumnLabel(r: { from: string; to: string }, ctx: PeriodContext): string {
  return wholePeriodLabel(r, ctx.calendar) ?? `${formatDate(r.from, prefsOf(ctx))} – ${formatDate(r.to, prefsOf(ctx))}`;
}

/**
 * The period before: a whole number of months (a month, a quarter, a year, a fiscal year, in the company's calendar) is
 * followed back by as many months (Q3 2026 → Q2 2026), as QuickBooks' "Previous period"; any other period by one of the
 * same number of days ending the day before it starts.
 */
export function periodBefore(r: { from: string; to: string }, ctx: PeriodContext): { from: string; to: string } {
  for (const k of [1, 2, 3, 4, 6, 12, 24]) {
    if (shiftDay(r.from, k, ctx.calendar) === addDays(r.to, 1) && shiftDay(addDays(r.to, 1), -k, ctx.calendar) === r.from) {
      return { from: shiftDay(r.from, -k, ctx.calendar), to: addDays(r.from, -1) };
    }
  }
  return previousPeriod(r.from, r.to);
}

const yearsBack = (r: { from: string; to: string }, n: number) => {
  let x = r;
  for (let i = 0; i < n; i++) x = samePeriodLastYear(x.from, x.to);
  return x;
};

function readSlots(q: Query, ctx: PeriodContext): Slot[] {
  const prefs = prefsOf(ctx);
  return SLOTS.map((n) => ({
    kind: one(q, `c${n}`) ?? "",
    from: parseDate(one(q, `c${n}_from`), prefs),
    to: parseDate(one(q, `c${n}_to`), prefs),
    asOf: parseDate(one(q, `c${n}_as_of`), prefs),
  }));
}

/** Whether the comparisons were chosen (the filter form sends c1 to c3, empty for none). */
const given = (q: Query) => SLOTS.some((n) => typeof q[`c${n}`] === "string");

export function pickDiff(q: Query, fallback: Diff): Diff {
  const d = one(q, "diff");
  return d === "none" || d === "amount" || d === "pct" || d === "both" ? d : fallback;
}

/**
 * The periods a report over `current` is compared with. Older links still work: compare=year is the same period last
 * year, compare=none none; with no comparison in the URL the report's `defaults` apply (the profit and loss compares
 * with the previous period, as it always has). An open period (All dates) has nothing to compare with.
 */
export function pickRangeComparisons(q: Query, current: { from: string | null; to: string | null }, ctx: PeriodContext, defaults: string[] = []):
  { slots: Slot[]; periods: Comparison[] } {
  let slots = readSlots(q, ctx);
  // Nothing about comparisons in the URL (an older link): the old compare= field, else the report's default.
  if (!given(q)) {
    const legacy = one(q, "compare") === "year" ? ["previous_year"] : one(q, "compare") === "none" ? [] : defaults;
    slots = SLOTS.map((_, i) => ({ kind: legacy[i] ?? "", from: null, to: null, asOf: null }));
  }
  const periods: Comparison[] = [];
  const cur = current.from && current.to ? { from: current.from, to: current.to } : null;
  for (const s of slots) {
    let r: { from: string; to: string } | null = null;
    if (s.kind === "previous_period" && cur) r = periodBefore(cur, ctx);
    else if (s.kind === "previous_year" && cur) r = yearsBack(cur, 1);
    else if (s.kind === "two_years_ago" && cur) r = yearsBack(cur, 2);
    else if (s.kind === "custom") r = s.from && s.to && s.from <= s.to ? { from: s.from, to: s.to } : null;
    else if ((CLOSED_RANGE_PRESETS as readonly string[]).includes(s.kind)) {
      const x = resolveRange(s.kind as RangePreset, ctx);
      r = x?.from && x.to ? { from: x.from, to: x.to } : null;
    }
    if (r && !periods.some((p) => p.from === r!.from && p.to === r!.to) && !(cur && cur.from === r.from && cur.to === r.to)) {
      periods.push({ ...r, label: periodColumnLabel(r, ctx) });
    }
  }
  return { slots, periods: periods.slice(0, MAX_COMPARISONS) };
}

/** The dates a report at `asOf` is compared with. Older links: compare=month|quarter|year (the balance sheet's). */
export function pickAsOfComparisons(q: Query, asOf: string, ctx: PeriodContext): { slots: Slot[]; dates: AsOfComparison[] } {
  let slots = readSlots(q, ctx);
  if (!given(q)) {
    const legacy = ({ month: "previous_month", quarter: "previous_quarter", year: "previous_year" } as Record<string, string>)[one(q, "compare") ?? ""];
    slots = SLOTS.map((_, i) => ({ kind: i === 0 && legacy ? legacy : "", from: null, to: null, asOf: null }));
  }
  const back: Record<string, number> = { previous_month: 1, previous_quarter: 3, previous_year: 12, two_years_ago: 24 };
  const dates: AsOfComparison[] = [];
  for (const s of slots) {
    let d: string | null = null;
    if (s.kind in back) d = shiftDay(asOf, -back[s.kind], ctx.calendar);
    else if (s.kind === "custom") d = s.asOf;
    else if (COMPARE_AS_OF_PRESETS.some((p) => p.key === s.kind && p.key !== "custom")) d = resolveAsOf(s.kind as AsOfPreset, ctx);
    if (d && d !== asOf && !dates.some((x) => x.asOf === d)) dates.push({ asOf: d, label: formatDate(d, prefsOf(ctx)) });
  }
  return { slots, dates: dates.slice(0, MAX_COMPARISONS) };
}

/** The comparison fields to carry in a link or an export: the slots as set, and the change columns. */
export function compareQuery(slots: Slot[], diff: Diff | null): Record<string, string> {
  const out: Record<string, string> = {};
  slots.forEach((s, i) => {
    const n = i + 1;
    if (!s.kind) return;
    out[`c${n}`] = s.kind;
    if (s.kind === "custom") {
      if (s.from) out[`c${n}_from`] = s.from;
      if (s.to) out[`c${n}_to`] = s.to;
      if (s.asOf) out[`c${n}_as_of`] = s.asOf;
    }
  });
  // None chosen: said so (an empty field would be dropped from a link, and the report's default come back).
  if (!slots.some((s) => s.kind && s.kind !== "none")) out.c1 = "none";
  if (diff) out.diff = diff;
  return out;
}

// ---- A statement with a column per period ---------------------------------------------------------------------

export type LineKind = "heading" | "row" | "total" | "key" | "grand";
/** One line of a statement: a section heading, an account, a total; a value per period (null: nothing there). */
export interface Line { key: string; label: string; kind: LineKind; values: (string | null)[]; code?: string }

/**
 * Rows of the same section from N results, joined by `key` (an account's code), in the order they first appear (the
 * report's own period first, then the comparisons): an account that had nothing in a period shows nothing there.
 */
export function mergeRows<T>(lists: (readonly T[])[], key: (t: T) => string, value: (t: T) => string, label: (t: T) => string, code?: (t: T) => string): Line[] {
  const order: string[] = [];
  const found = new Map<string, T>();
  lists.forEach((list) => list.forEach((t) => { const k = key(t); if (!found.has(k)) { found.set(k, t); order.push(k); } }));
  const maps = lists.map((list) => new Map(list.map((t) => [key(t), t])));
  return order.map((k) => {
    const t = found.get(k)!;
    return { key: k, label: label(t), kind: "row" as const, code: code?.(t), values: maps.map((m) => (m.has(k) ? value(m.get(k)!) : null)) };
  });
}

/** $ change: this period less the first comparison (nothing there counts as zero). */
export function amountChange(values: (string | null)[]): string | null {
  if (values.length < 2) return null;
  return toString(parse(values[0] ?? "0.00") - parse(values[1] ?? "0.00"));
}

/** % change against the first comparison, as QuickBooks writes it ("+12.5%", "—" when there is nothing to divide by). */
export function pctChange(values: (string | null)[]): string {
  if (values.length < 2) return "—";
  return percentChange(values[0] ?? "0.00", values[1] ?? "0.00");
}

/** Whether a line shows: a heading always, an account or total when any period has something. */
export const shows = (l: Line) => l.kind === "heading" || l.values.some((v) => v !== null && v !== "0.00") || l.kind === "grand" || l.kind === "key";

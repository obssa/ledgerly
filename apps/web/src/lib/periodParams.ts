import { isDate } from "./reports";
import { isAsOfPreset, isRangePreset, matchRange, resolveAsOf, resolveRange, type AsOfPreset, type PeriodContext, type RangePreset } from "./periods";

/**
 * Which period a page shows, from its URL. The date filter is a plain GET form (it works without JavaScript): a preset
 * list (`range`), the from and to dates, and the preset the page was showing (`shown`). So on submit:
 *   - a different preset was picked: that preset, whatever the dates say;
 *   - the same preset, but other dates typed: those dates (Custom);
 *   - no preset in the URL (an older link, a drill-down, a saved report): the dates, named after the preset they match.
 */
export interface PickedRange { preset: RangePreset; from: string | null; to: string | null }
export interface PickedAsOf { preset: AsOfPreset; asOf: string }

type Query = Record<string, string | string[] | undefined>;
const one = (q: Query, k: string) => (typeof q[k] === "string" ? (q[k] as string) : undefined);

export function pickRange(q: Query, ctx: PeriodContext, fallback: RangePreset, allowed?: readonly RangePreset[]): PickedRange {
  const ok = (p: RangePreset) => !allowed || allowed.includes(p);
  const range = one(q, "range"), shown = one(q, "shown");
  const from = isDate(one(q, "from")) ? one(q, "from")! : null;
  const to = isDate(one(q, "to")) ? one(q, "to")! : null;
  const typed = from !== null || to !== null;

  if (isRangePreset(range) && ok(range)) {
    if (range === "custom") return typed ? { preset: "custom", from, to } : named(fallback, ctx);
    const preset = resolveRange(range, ctx)!;
    const editedDates = range === shown && typed && (from !== preset.from || to !== preset.to);
    return editedDates ? { preset: "custom", from, to } : { preset: range, ...preset };
  }
  if (typed) return { preset: matchRange({ from, to }, ctx, allowed), from, to };
  return named(fallback, ctx);
}

function named(preset: RangePreset, ctx: PeriodContext): PickedRange {
  return { preset, ...(resolveRange(preset, ctx) ?? { from: ctx.today, to: ctx.today }) };
}

/** The same for a report at one date ("As of"): `range` is an as-of preset, `as_of` the date. */
export function pickAsOf(q: Query, ctx: PeriodContext, fallback: AsOfPreset = "today"): PickedAsOf {
  const range = one(q, "range"), shown = one(q, "shown");
  const typed = isDate(one(q, "as_of")) ? one(q, "as_of")! : null;
  if (isAsOfPreset(range) && range !== "custom") {
    const date = resolveAsOf(range, ctx)!;
    return range === shown && typed && typed !== date ? { preset: "custom", asOf: typed } : { preset: range, asOf: date };
  }
  if (typed) {
    const match = (["today", "yesterday", "end_of_last_week", "end_of_last_month", "end_of_last_quarter", "end_of_last_year", "end_of_last_fiscal_year"] as const)
      .find((p) => resolveAsOf(p, ctx) === typed);
    return { preset: match ?? "custom", asOf: typed };
  }
  return { preset: fallback, asOf: resolveAsOf(fallback, ctx) ?? ctx.today };
}

/** An open end, for an API that wants both dates: the earliest and latest dates the books can hold. */
export const OPEN_FROM = "1900-01-01";
export const OPEN_TO = "2999-12-31";
export const apiDates = (r: { from: string | null; to: string | null }) => ({ from: r.from ?? OPEN_FROM, to: r.to ?? OPEN_TO });

/** The URL fields that bring a page back to this period: the preset, plus the dates when they are the user's own. */
export function periodQuery(p: PickedRange): Record<string, string> {
  return p.preset === "custom" ? { range: "custom", ...(p.from ? { from: p.from } : {}), ...(p.to ? { to: p.to } : {}) } : { range: p.preset };
}

export function asOfQuery(p: PickedAsOf): Record<string, string> {
  return p.preset === "custom" ? { range: "custom", as_of: p.asOf } : { range: p.preset };
}

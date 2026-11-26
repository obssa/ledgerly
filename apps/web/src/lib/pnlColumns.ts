import { splitRange, type Calendar, type SplitUnit } from "./periods";
import type { PnlColumnsBy } from "./api";
import { parse } from "./decimal";

/** QuickBooks' "Display columns by" for the profit and loss, in its order. */
export const DISPLAY_BY = [["total", "Total only"], ["month", "Months"], ["quarter", "Quarters"], ["year", "Years"], ["contact", "Customers and suppliers"],
  ["location", "Locations"], ["class", "Classes"], ["project", "Projects"]] as const;
export type DisplayBy = (typeof DISPLAY_BY)[number][0];
export const pickDisplayBy = (v: unknown): DisplayBy => (DISPLAY_BY.some(([k]) => k === v) ? (v as DisplayBy) : "total");

/**
 * What to ask the API for: the grouping, and for a period grouping the first day of each column with its header in
 * the company's calendar. null when there would be more than 60 columns (a shorter period, or longer columns).
 */
export function columnsRequest(by: Exclude<DisplayBy, "total">, range: { from: string; to: string }, cal: Calendar):
  { by: PnlColumnsBy; starts?: string; labels?: string[] } | null {
  if (by === "contact" || by === "location" || by === "class" || by === "project") return { by };
  const split = splitRange(range, by as SplitUnit, cal);
  if (!split) return null;
  return { by: "period", starts: split.map((s) => s.from).join(","), labels: split.map((s) => s.label) };
}

/** A share of income, to one decimal ("12.5%"); a dash when there is no income. Exact: decimal strings, no floats. */
export function percentOf(part: string, whole: string): string {
  const w = parse(whole);
  if (w === 0n) return "—";
  const tenths = (parse(part) * 1000n) / w;
  const abs = tenths < 0n ? -tenths : tenths;
  return `${tenths < 0n ? "−" : ""}${abs / 10n}.${abs % 10n}%`;
}

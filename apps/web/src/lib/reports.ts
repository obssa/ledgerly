/* Module 10: periods for reports. Dates are YYYY-MM-DD strings, compared and shifted in UTC (no time zone involved). */

const DAY = 86_400_000;
const toDate = (s: string) => new Date(`${s}T00:00:00Z`);
const toString = (d: Date) => d.toISOString().slice(0, 10);

export const isDate = (v: unknown): v is string => typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(toDate(v).getTime());

/** The period of the same length that ends the day before this one starts: the comparison column. */
export function previousPeriod(from: string, to: string): { from: string; to: string } {
  const days = Math.round((toDate(to).getTime() - toDate(from).getTime()) / DAY);
  const end = new Date(toDate(from).getTime() - DAY);
  return { from: toString(new Date(end.getTime() - days * DAY)), to: toString(end) };
}

/** A period as words: "1 Jan 2026 to 17 Nov 2026", or one month as "October 2026". */
export function periodLabel(from: string, to: string): string {
  const f = toDate(from);
  const t = toDate(to);
  const fmt = (d: Date, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", ...opts }).format(d);
  const lastOfMonth = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate();
  if (f.getUTCDate() === 1 && t.getUTCDate() === lastOfMonth && f.getUTCMonth() === t.getUTCMonth() && f.getUTCFullYear() === t.getUTCFullYear()) {
    return fmt(f, { month: "long", year: "numeric" });
  }
  return `${fmt(f, { day: "numeric", month: "short", year: "numeric" })} to ${fmt(t, { day: "numeric", month: "short", year: "numeric" })}`;
}

/** A ratio from the API for display: null as a dash, a percentage or a number of days or months with its unit. */
export function showRatio(v: string | null, unit: "" | "%" | " days" | " months" = ""): string {
  return v === null ? "—" : `${v}${unit}`;
}

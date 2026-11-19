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

/** The same date a year earlier; 29 February becomes 28 February. */
export function yearEarlier(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  const last = new Date(Date.UTC(y - 1, m, 0)).getUTCDate();
  return `${y - 1}-${String(m).padStart(2, "0")}-${String(Math.min(d, last)).padStart(2, "0")}`;
}

/** The same period a year earlier: comparison with last year. */
export function samePeriodLastYear(from: string, to: string): { from: string; to: string } {
  return { from: yearEarlier(from), to: yearEarlier(to) };
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

/** A stock ageing band as words: slow-moving is 91 to 180 days since the last sale, dead stock over 180 or never sold. */
export const STOCK_BANDS: Record<string, { label: string; note?: string }> = {
  "0-30": { label: "Sold in the last 30 days" },
  "31-90": { label: "31 to 90 days" },
  "91-180": { label: "91 to 180 days", note: "slow-moving" },
  "over-180": { label: "Over 180 days", note: "dead stock" },
  never: { label: "Never sold", note: "dead stock" },
};

/** A ratio from the API for display: null as a dash, a percentage or a number of days or months with its unit. */
export function showRatio(v: string | null, unit: "" | "%" | " days" | " months" = ""): string {
  return v === null ? "—" : `${v}${unit}`;
}

/** Module 10, custom report: the choices, as the API lists them (CustomReport). */
export const CUSTOM_ROWS = [["account", "Account"], ["account_type", "Account type"], ["contact", "Customer or supplier"], ["project", "Project"], ["location", "Location"], ["class", "Class"]] as const;
export const CUSTOM_COLUMNS = [["total", "The whole period"], ["month", "Each month"], ["quarter", "Each quarter"]] as const;
export const CUSTOM_MEASURES = [["natural", "By its own side"], ["debit", "Debits"], ["credit", "Credits"], ["net", "Debits less credits"]] as const;
export const CUSTOM_TYPES = [["asset", "Assets"], ["liability", "Liabilities"], ["equity", "Equity"], ["income", "Income"], ["expense", "Expenses"]] as const;

export interface CustomOptions {
  rows: string; columns: string; measure: string;
  account_type?: string; project_id?: string; location_id?: string; class_id?: string;
}

const pick = (list: readonly (readonly [string, string])[], v: unknown, fallback?: string) =>
  typeof v === "string" && list.some(([k]) => k === v) ? v : fallback;
const id = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

/** The custom report's choices from the address: only known values, anything else falls back. */
export function customOptions(q: Record<string, string | string[] | undefined>): CustomOptions {
  return {
    rows: pick(CUSTOM_ROWS, q.rows, "account") as string,
    columns: pick(CUSTOM_COLUMNS, q.columns, "total") as string,
    measure: pick(CUSTOM_MEASURES, q.measure, "natural") as string,
    account_type: pick(CUSTOM_TYPES, q.account_type),
    project_id: id(q.project_id), location_id: id(q.location_id), class_id: id(q.class_id),
  };
}

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/** "2026-11" → "Nov 2026"; "2026-Q4" → "Q4 2026"; anything else as it is. */
export function columnLabel(c: string): string {
  const m = /^(\d{4})-(\d{2})$/.exec(c);
  if (m) return `${MONTHS[Number(m[2]) - 1] ?? m[2]} ${m[1]}`;
  const q = /^(\d{4})-(Q[1-4])$/.exec(c);
  return q ? `${q[2]} ${q[1]}` : c;
}

/*
 * "Today" as a date (YYYY-MM-DD) in a time zone. `new Date().toISOString()` is the UTC date, which in Addis Ababa
 * (UTC+3) is still yesterday for the first three hours of every day: default periods then stopped at yesterday and
 * date fields offered yesterday. The API decides with the company's own today (CurrentCompany::today()); pages use the
 * company's time zone, and browser forms the launch one, so the page rendered on the server and in the browser agree.
 */

import { fromGregorian, toGregorian, daysInMonth as ethDaysInMonth } from "./ethiopian";

export const DEFAULT_TIME_ZONE = "Africa/Addis_Ababa";

// ---- How the company writes dates (Settings, Date format) -----------------------------------------------------------

/**
 * The company's date format, as QuickBooks offers it (Settings → Advanced → Date format): day first by default
 * (27/11/2026), month first (11/27/2026), or ISO (2026-11-27). Ethiopian-calendar companies see their own calendar's
 * numbers in the same order (17/03/2019 for Hidar 17, 2019). Stored dates are always Gregorian "YYYY-MM-DD".
 */
export const DATE_FORMATS = ["DD/MM/YYYY", "MM/DD/YYYY", "YYYY-MM-DD"] as const;
export type DateFormat = (typeof DATE_FORMATS)[number];
export const DEFAULT_DATE_FORMAT: DateFormat = "DD/MM/YYYY";
export type DateCalendar = "gregorian" | "ethiopian";

export interface DatePrefs { calendar: DateCalendar; dateFormat: DateFormat }
export const DEFAULT_DATE_PREFS: DatePrefs = { calendar: "gregorian", dateFormat: DEFAULT_DATE_FORMAT };

/** The names a date field (DateInput) adds to its form: what was typed, and how the company writes dates ("DD/MM/YYYY|gregorian"). */
export const TYPED = "__typed";
export const DATE_PREFS_FIELD = "__date_prefs";

/** A stored or sent format, or the default when it is missing or unknown. */
export function pickDateFormat(value: unknown): DateFormat {
  return (DATE_FORMATS as readonly unknown[]).includes(value) ? (value as DateFormat) : DEFAULT_DATE_FORMAT;
}

const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})/;
const pad = (n: number, w = 2) => String(n).padStart(w, "0");

/** Year, month, day in the company's calendar. */
function partsOf(iso: string, calendar: DateCalendar): { y: number; m: number; d: number } {
  if (calendar === "ethiopian") { const e = fromGregorian(iso); return { y: e.year, m: e.month, d: e.day }; }
  const [, y, m, d] = ISO_DAY.exec(iso)!.map(Number);
  return { y, m, d };
}

function write(p: { y: number; m: number; d: number }, format: DateFormat): string {
  if (format === "MM/DD/YYYY") return `${pad(p.m)}/${pad(p.d)}/${pad(p.y, 4)}`;
  if (format === "YYYY-MM-DD") return `${pad(p.y, 4)}-${pad(p.m)}-${pad(p.d)}`;
  return `${pad(p.d)}/${pad(p.m)}/${pad(p.y, 4)}`;
}

/**
 * A stored date ("2026-11-27", or the date part of a timestamp) as the company writes it: "27/11/2026". Empty →
 * `empty`; anything that is not a date comes back as it is.
 */
export function formatDate(iso: string | null | undefined, prefs: DatePrefs = DEFAULT_DATE_PREFS, empty = "—"): string {
  if (!iso) return empty;
  if (!ISO_DAY.test(iso)) return iso;
  return write(partsOf(iso.slice(0, 10), prefs.calendar), prefs.dateFormat);
}

/** A moment (an ISO timestamp) as the company's date and 24-hour time in its time zone: "27/11/2026 14:05". */
export function formatDateTime(timestamp: string | null | undefined, prefs: DatePrefs = DEFAULT_DATE_PREFS, timeZone?: string | null, empty = "—"): string {
  if (!timestamp) return empty;
  const at = new Date(timestamp);
  if (Number.isNaN(at.getTime())) return timestamp;
  const parts = (zone: string) => new Intl.DateTimeFormat("en-CA", { timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" })
    .formatToParts(at).reduce<Record<string, string>>((a, p) => ({ ...a, [p.type]: p.value }), {});
  let p: Record<string, string>;
  try { p = parts(timeZone || DEFAULT_TIME_ZONE); } catch { p = parts(DEFAULT_TIME_ZONE); }
  return `${formatDate(`${p.year}-${p.month}-${p.day}`, prefs)} ${p.hour}:${p.minute}`;
}

/**
 * What someone typed in a date field, in the company's format ("27/11/2026"; "-" or "." also separate) or as ISO
 * ("2026-11-27"), to the stored Gregorian "YYYY-MM-DD". Strictly in the company's order (03/04/2026 is never guessed);
 * null when it is not a real date. Ethiopian-calendar companies type their own calendar's numbers.
 */
export function parseDate(text: string | null | undefined, prefs: DatePrefs = DEFAULT_DATE_PREFS): string | null {
  const s = (text ?? "").trim();
  if (!s) return null;
  const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s);
  // An ISO date (from a URL, a browser or an import) is always Gregorian.
  if (iso) return validGregorian(Number(iso[1]), Number(iso[2]), Number(iso[3]));
  const local = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(s);
  if (!local) return null;
  const [a, b, y] = [Number(local[1]), Number(local[2]), Number(local[3])];
  const [m, d] = prefs.dateFormat === "MM/DD/YYYY" ? [a, b] : [b, a];
  if (prefs.calendar === "ethiopian") {
    if (y < 1 || m < 1 || m > 13 || d < 1 || d > ethDaysInMonth(y, m)) return null;
    return toGregorian(y, m, d);
  }
  return validGregorian(y, m, d);
}

function validGregorian(y: number, m: number, d: number): string | null {
  if (y < 1 || m < 1 || m > 12 || d < 1) return null;
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return d > last ? null : `${pad(y, 4)}-${pad(m)}-${pad(d)}`;
}

/** Today in the time zone (an IANA name such as Africa/Nairobi); an unknown or empty one falls back to the default. */
export function todayIn(timeZone?: string | null, now: Date = new Date()): string {
  const format = (zone: string) => new Intl.DateTimeFormat("en-CA", { timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit" }).format(now);
  try {
    return format(timeZone || DEFAULT_TIME_ZONE);
  } catch {
    return format(DEFAULT_TIME_ZONE);
  }
}

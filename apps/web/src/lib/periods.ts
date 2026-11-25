import { daysInMonth as ethDaysInMonth, ETHIOPIAN_MONTHS, fromGregorian, toGregorian } from "./ethiopian";

/**
 * Report periods, as QuickBooks offers them ("Report period": All dates, Today, This month to date, Last fiscal
 * year, Since 30 days ago …), worked out in the company's own calendar. A Gregorian company's months are January to
 * December; an Ethiopian-calendar company's months are Meskerem to Nehase, Pagume counted with Nehase (as the fiscal
 * periods are, app/Support/FiscalCalendar.php), its year runs from Meskerem 1 and its quarters are three of its months.
 * Weeks run Monday to Sunday. Every date is a Gregorian "YYYY-MM-DD", as stored; pure functions, no time zone.
 */
export type Calendar = "gregorian" | "ethiopian";

export interface PeriodContext {
  /** The company's today ("YYYY-MM-DD", in its time zone). */
  today: string;
  calendar: Calendar;
  /** First month of the fiscal year, in the company's calendar (1-12). */
  fiscalStartMonth: number;
  /** The company's fiscal years as set up (they win over the start month, e.g. a first, short year). */
  fiscalYears?: { starts_on: string; ends_on: string }[];
}

/** A period; null means open at that end (All dates, Since 30 days ago). */
export interface Range { from: string | null; to: string | null }

export const RANGE_PRESETS = [
  { key: "all", label: "All dates", group: "" },
  { key: "custom", label: "Custom", group: "" },
  { key: "today", label: "Today", group: "Days" },
  { key: "yesterday", label: "Yesterday", group: "Days" },
  { key: "this_week", label: "This week", group: "Weeks" },
  { key: "this_week_to_date", label: "This week to date", group: "Weeks" },
  { key: "last_week", label: "Last week", group: "Weeks" },
  { key: "last_week_to_date", label: "Last week to date", group: "Weeks" },
  { key: "next_week", label: "Next week", group: "Weeks" },
  { key: "next_4_weeks", label: "Next 4 weeks", group: "Weeks" },
  { key: "this_month", label: "This month", group: "Months" },
  { key: "this_month_to_date", label: "This month to date", group: "Months" },
  { key: "last_month", label: "Last month", group: "Months" },
  { key: "last_month_to_date", label: "Last month to date", group: "Months" },
  { key: "next_month", label: "Next month", group: "Months" },
  { key: "this_quarter", label: "This quarter", group: "Quarters" },
  { key: "this_quarter_to_date", label: "This quarter to date", group: "Quarters" },
  { key: "last_quarter", label: "Last quarter", group: "Quarters" },
  { key: "last_quarter_to_date", label: "Last quarter to date", group: "Quarters" },
  { key: "next_quarter", label: "Next quarter", group: "Quarters" },
  { key: "this_year", label: "This year", group: "Years" },
  { key: "this_year_to_date", label: "This year to date", group: "Years" },
  { key: "this_year_to_last_month", label: "This year to last month", group: "Years" },
  { key: "last_year", label: "Last year", group: "Years" },
  { key: "last_year_to_date", label: "Last year to date", group: "Years" },
  { key: "next_year", label: "Next year", group: "Years" },
  { key: "this_fiscal_year", label: "This fiscal year", group: "Fiscal" },
  { key: "this_fiscal_year_to_date", label: "This fiscal year to date", group: "Fiscal" },
  { key: "this_fiscal_year_to_last_month", label: "This fiscal year to last month", group: "Fiscal" },
  { key: "last_fiscal_year", label: "Last fiscal year", group: "Fiscal" },
  { key: "last_fiscal_year_to_date", label: "Last fiscal year to date", group: "Fiscal" },
  { key: "this_fiscal_quarter", label: "This fiscal quarter", group: "Fiscal" },
  { key: "this_fiscal_quarter_to_date", label: "This fiscal quarter to date", group: "Fiscal" },
  { key: "last_fiscal_quarter", label: "Last fiscal quarter", group: "Fiscal" },
  { key: "last_7_days", label: "Last 7 days", group: "Rolling" },
  { key: "last_30_days", label: "Last 30 days", group: "Rolling" },
  { key: "last_90_days", label: "Last 90 days", group: "Rolling" },
  { key: "last_365_days", label: "Last 365 days", group: "Rolling" },
  { key: "last_12_months", label: "Last 12 months", group: "Rolling" },
  { key: "since_30_days_ago", label: "Since 30 days ago", group: "Since" },
  { key: "since_60_days_ago", label: "Since 60 days ago", group: "Since" },
  { key: "since_90_days_ago", label: "Since 90 days ago", group: "Since" },
  { key: "since_365_days_ago", label: "Since 365 days ago", group: "Since" },
] as const;
export type RangePreset = (typeof RANGE_PRESETS)[number]["key"];

/** For reports at a single date ("As of"). */
export const AS_OF_PRESETS = [
  { key: "today", label: "Today" },
  { key: "yesterday", label: "Yesterday" },
  { key: "end_of_last_week", label: "End of last week" },
  { key: "end_of_last_month", label: "End of last month" },
  { key: "end_of_last_quarter", label: "End of last quarter" },
  { key: "end_of_last_year", label: "End of last year" },
  { key: "end_of_last_fiscal_year", label: "End of last fiscal year" },
  { key: "custom", label: "Custom" },
] as const;
export type AsOfPreset = (typeof AS_OF_PRESETS)[number]["key"];

export const isRangePreset = (v: unknown): v is RangePreset => typeof v === "string" && RANGE_PRESETS.some((p) => p.key === v);
export const isAsOfPreset = (v: unknown): v is AsOfPreset => typeof v === "string" && AS_OF_PRESETS.some((p) => p.key === v);

// ---- Days and weeks (Gregorian ISO dates, UTC midnight) --------------------------------------------------------

const toDate = (iso: string) => new Date(`${iso}T00:00:00Z`);
const toIso = (d: Date) => d.toISOString().slice(0, 10);
export const addDays = (iso: string, n: number): string => { const d = toDate(iso); d.setUTCDate(d.getUTCDate() + n); return toIso(d); };
/** Monday = 0 … Sunday = 6. */
const weekday = (iso: string) => (toDate(iso).getUTCDay() + 6) % 7;
const weekStart = (iso: string) => addDays(iso, -weekday(iso));

// ---- Months in the company's calendar ----------------------------------------------------------------------------

interface Month { y: number; m: number }   // m 1-12; an Ethiopian month 12 includes Pagume

function monthOf(iso: string, cal: Calendar): Month {
  if (cal === "ethiopian") { const e = fromGregorian(iso); return { y: e.year, m: Math.min(e.month, 12) }; }
  const [y, m] = iso.split("-").map(Number);
  return { y, m };
}

function shiftMonth(mo: Month, k: number): Month {
  const i = mo.y * 12 + (mo.m - 1) + k;
  return { y: Math.floor(i / 12), m: (i % 12 + 12) % 12 + 1 };
}

const gregLastDay = (y: number, m: number) => new Date(Date.UTC(y, m, 0)).getUTCDate();
const pad = (n: number) => String(n).padStart(2, "0");

function monthStart(mo: Month, cal: Calendar): string {
  return cal === "ethiopian" ? toGregorian(mo.y, mo.m, 1) : `${mo.y}-${pad(mo.m)}-01`;
}

function monthEnd(mo: Month, cal: Calendar): string {
  if (cal === "ethiopian") return mo.m === 12 ? toGregorian(mo.y, 13, ethDaysInMonth(mo.y, 13)) : toGregorian(mo.y, mo.m, 30);
  return `${mo.y}-${pad(mo.m)}-${pad(gregLastDay(mo.y, mo.m))}`;
}

/** The same day k months away, in the company's calendar, kept inside the target month (31 Jan → 28 Feb). */
function shiftDay(iso: string, k: number, cal: Calendar): string {
  if (cal === "ethiopian") {
    const e = fromGregorian(iso);
    const day = e.month === 13 ? 30 : e.day;   // a Pagume day counts as the end of Nehase
    const t = shiftMonth({ y: e.year, m: Math.min(e.month, 12) }, k);
    return toGregorian(t.y, t.m, Math.min(day, 30));
  }
  const [y, m, d] = iso.split("-").map(Number);
  const t = shiftMonth({ y, m }, k);
  return `${t.y}-${pad(t.m)}-${pad(Math.min(d, gregLastDay(t.y, t.m)))}`;
}

const span = (first: Month, months: number, cal: Calendar): Range => ({ from: monthStart(first, cal), to: monthEnd(shiftMonth(first, months - 1), cal) });
const quarterFirst = (mo: Month): Month => ({ y: mo.y, m: Math.floor((mo.m - 1) / 3) * 3 + 1 });

// ---- Fiscal years ------------------------------------------------------------------------------------------------

function fiscalYear(ctx: PeriodContext, offset: number): Range {
  const set = [...(ctx.fiscalYears ?? [])].sort((a, b) => a.starts_on.localeCompare(b.starts_on));
  const here = set.findIndex((f) => f.starts_on <= ctx.today && ctx.today <= f.ends_on);
  if (here >= 0 && set[here + offset]) return { from: set[here + offset].starts_on, to: set[here + offset].ends_on };
  // Not set up (or not that far): from the start month, in the company's calendar.
  const now = monthOf(ctx.today, ctx.calendar);
  const first: Month = { y: now.m >= ctx.fiscalStartMonth ? now.y : now.y - 1, m: ctx.fiscalStartMonth };
  return span(shiftMonth(first, 12 * offset), 12, ctx.calendar);
}

/** The fiscal quarter containing today (offset 0) or before it (-1): three months counted from the fiscal year start. */
function fiscalQuarter(ctx: PeriodContext, offset: number): Range {
  const year = fiscalYear(ctx, 0);
  const first = monthOf(year.from!, ctx.calendar);
  const now = monthOf(ctx.today, ctx.calendar);
  const sinceStart = (now.y * 12 + now.m) - (first.y * 12 + first.m);
  return span(shiftMonth(first, (Math.floor(sinceStart / 3) + offset) * 3), 3, ctx.calendar);
}

// ---- Presets -----------------------------------------------------------------------------------------------------

/** The dates a preset stands for on the company's today. "custom" has none of its own: null. */
export function resolveRange(preset: RangePreset, ctx: PeriodContext): Range | null {
  const { today, calendar: cal } = ctx;
  const month = monthOf(today, cal);
  const quarter = quarterFirst(month);
  const year: Month = { y: month.y, m: 1 };
  // "… to last month": from the start of the period to the end of last month. In the period's first month there is no
  // finished month in it yet: null, and the caller gives the whole previous period instead.
  const toLastMonth = (from: string): Range | null => {
    const to = monthEnd(shiftMonth(month, -1), cal);
    return to < from ? null : { from, to };
  };

  switch (preset) {
    case "all": return { from: null, to: null };
    case "custom": return null;
    case "today": return { from: today, to: today };
    case "yesterday": return { from: addDays(today, -1), to: addDays(today, -1) };
    case "this_week": return { from: weekStart(today), to: addDays(weekStart(today), 6) };
    case "this_week_to_date": return { from: weekStart(today), to: today };
    case "last_week": return { from: addDays(weekStart(today), -7), to: addDays(weekStart(today), -1) };
    case "last_week_to_date": return { from: addDays(weekStart(today), -7), to: addDays(today, -7) };
    case "next_week": return { from: addDays(weekStart(today), 7), to: addDays(weekStart(today), 13) };
    case "next_4_weeks": return { from: today, to: addDays(today, 27) };
    case "this_month": return span(month, 1, cal);
    case "this_month_to_date": return { from: monthStart(month, cal), to: today };
    case "last_month": return span(shiftMonth(month, -1), 1, cal);
    case "last_month_to_date": return { from: monthStart(shiftMonth(month, -1), cal), to: shiftDay(today, -1, cal) };
    case "next_month": return span(shiftMonth(month, 1), 1, cal);
    case "this_quarter": return span(quarter, 3, cal);
    case "this_quarter_to_date": return { from: monthStart(quarter, cal), to: today };
    case "last_quarter": return span(shiftMonth(quarter, -3), 3, cal);
    case "last_quarter_to_date": return { from: monthStart(shiftMonth(quarter, -3), cal), to: shiftDay(today, -3, cal) };
    case "next_quarter": return span(shiftMonth(quarter, 3), 3, cal);
    case "this_year": return span(year, 12, cal);
    case "this_year_to_date": return { from: monthStart(year, cal), to: today };
    case "this_year_to_last_month": return toLastMonth(monthStart(year, cal)) ?? span(shiftMonth(year, -12), 12, cal);
    case "last_year": return span(shiftMonth(year, -12), 12, cal);
    case "last_year_to_date": return { from: monthStart(shiftMonth(year, -12), cal), to: shiftDay(today, -12, cal) };
    case "next_year": return span(shiftMonth(year, 12), 12, cal);
    case "this_fiscal_year": return fiscalYear(ctx, 0);
    case "this_fiscal_year_to_date": return { from: fiscalYear(ctx, 0).from, to: today };
    case "this_fiscal_year_to_last_month": return toLastMonth(fiscalYear(ctx, 0).from!) ?? fiscalYear(ctx, -1);
    case "last_fiscal_year": return fiscalYear(ctx, -1);
    case "last_fiscal_year_to_date": return { from: fiscalYear(ctx, -1).from, to: shiftDay(today, -12, cal) };
    case "this_fiscal_quarter": return fiscalQuarter(ctx, 0);
    case "this_fiscal_quarter_to_date": return { from: fiscalQuarter(ctx, 0).from, to: today };
    case "last_fiscal_quarter": return fiscalQuarter(ctx, -1);
    case "last_7_days": return { from: addDays(today, -6), to: today };
    case "last_30_days": return { from: addDays(today, -29), to: today };
    case "last_90_days": return { from: addDays(today, -89), to: today };
    case "last_365_days": return { from: addDays(today, -364), to: today };
    case "last_12_months": return span(shiftMonth(month, -12), 12, cal);
    case "since_30_days_ago": return { from: addDays(today, -30), to: null };
    case "since_60_days_ago": return { from: addDays(today, -60), to: null };
    case "since_90_days_ago": return { from: addDays(today, -90), to: null };
    case "since_365_days_ago": return { from: addDays(today, -365), to: null };
  }
}

/** The date an "As of" preset stands for. "custom" has none of its own: null. */
export function resolveAsOf(preset: AsOfPreset, ctx: PeriodContext): string | null {
  const { today, calendar: cal } = ctx;
  const month = monthOf(today, cal);
  switch (preset) {
    case "today": return today;
    case "yesterday": return addDays(today, -1);
    case "end_of_last_week": return addDays(weekStart(today), -1);
    case "end_of_last_month": return monthEnd(shiftMonth(month, -1), cal);
    case "end_of_last_quarter": return monthEnd(shiftMonth(quarterFirst(month), -1), cal);
    case "end_of_last_year": return monthEnd({ y: month.y - 1, m: 12 }, cal);
    case "end_of_last_fiscal_year": return fiscalYear(ctx, -1).to;
    case "custom": return null;
  }
}

/** Which preset, if any, gives exactly these dates today (so a shared link shows "Last month", not "Custom"). */
export function matchRange(range: Range, ctx: PeriodContext, allowed: readonly RangePreset[] = RANGE_PRESETS.map((p) => p.key)): RangePreset {
  for (const key of allowed) {
    if (key === "custom") continue;
    const r = resolveRange(key, ctx);
    if (r && r.from === range.from && r.to === range.to) return key;
  }
  return "custom";
}

// ---- Words -------------------------------------------------------------------------------------------------------

const GREGORIAN_MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/** "26 November 2026", or "Hidar 17, 2019" in the Ethiopian calendar. */
export function formatDay(iso: string, cal: Calendar): string {
  if (cal === "ethiopian") { const e = fromGregorian(iso); return `${ETHIOPIAN_MONTHS[e.month - 1]} ${e.day}, ${e.year}`; }
  const [y, m, d] = iso.split("-").map(Number);
  return `${d} ${GREGORIAN_MONTHS[m - 1]} ${y}`;
}

/** What a report header says about its period: "1 July 2026 – 26 November 2026", "All dates", "From 27 October 2026". */
export function rangeLabel(range: Range, cal: Calendar): string {
  if (!range.from && !range.to) return "All dates";
  if (!range.from) return `Up to ${formatDay(range.to!, cal)}`;
  if (!range.to) return `From ${formatDay(range.from, cal)}`;
  if (range.from === range.to) return formatDay(range.from, cal);
  return `${formatDay(range.from, cal)} – ${formatDay(range.to, cal)}`;
}

export const asOfLabel = (iso: string, cal: Calendar) => `As of ${formatDay(iso, cal)}`;

/** Presets with both ends: for reports that need a start and an end (ratios over a number of days). */
export const CLOSED_RANGE_PRESETS: readonly RangePreset[] = RANGE_PRESETS.map((p) => p.key).filter((k) => k !== "all" && !k.startsWith("since_"));

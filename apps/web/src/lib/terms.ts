/**
 * QuickBooks' Terms (database/schema/109): the due date a term gives a document from its date, worked out as the
 * person fills the form. The same rule as apps/api/app/Domain/Sales/PaymentTerms.php, tested on the same dates.
 *
 * - Days ("Net 30"): the date plus the days.
 * - Day of the month ("Due end of month", "Due on the 10th"): that day of the document's month in the company's
 *   calendar, or of the next month when the document is dated after it or within next_month_within days of it. Day 31
 *   is the month's last day. In the Ethiopian calendar Pagume counts with Nehase: Nehase's last day is Pagume's last.
 */
import { daysInMonth as ethiopianDays, fromGregorian, toGregorian } from "./ethiopian";

export interface PaymentTermRule {
  kind: "days" | "day_of_month";
  days?: number | null;
  day_of_month?: number | null;
  next_month_within?: number | null;
}

export type Calendar = "gregorian" | "ethiopian";

const iso = (y: number, m: number, d: number) => `${String(y).padStart(4, "0")}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

function addDays(date: string, days: number): string {
  const [y, m, d] = date.split("-").map(Number);
  const t = new Date(Date.UTC(y, m - 1, d + days));
  return iso(t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate());
}

/** Year, month 1-12 and day in it, in the company's calendar (Pagume's days follow Nehase's 30). */
function position(date: string, calendar: Calendar): [number, number, number] {
  if (calendar === "ethiopian") {
    const e = fromGregorian(date);
    return e.month === 13 ? [e.year, 12, 30 + e.day] : [e.year, e.month, e.day];
  }
  const [y, m, d] = date.split("-").map(Number);
  return [y, m, d];
}

function length(y: number, m: number, calendar: Calendar): number {
  if (calendar === "ethiopian") return m === 12 ? 30 + ethiopianDays(y, 13) : 30;
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

function toIso(y: number, m: number, d: number, calendar: Calendar): string {
  if (calendar === "ethiopian") return d > 30 ? toGregorian(y, 13, d - 30) : toGregorian(y, m, d);
  return iso(y, m, d);
}

/** The due date for a document dated `issue` (YYYY-MM-DD) on `term`; "" when the date is not a whole date yet. */
export function dueDate(issue: string, term: PaymentTermRule, calendar: Calendar = "gregorian"): string {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(issue)) return "";
  if (term.kind === "days") return addDays(issue, term.days ?? 0);

  const want = term.day_of_month ?? 31;
  const within = term.next_month_within ?? 0;
  const dayIn = (y: number, m: number) => (want === 31 ? length(y, m, calendar) : Math.min(want, length(y, m, calendar)));
  const [year, month, d] = position(issue, calendar);
  let [y, m] = [year, month];
  let due = dayIn(y, m);
  if (due < d || due - d < within) {
    [y, m] = m === 12 ? [y + 1, 1] : [y, m + 1];
    due = dayIn(y, m);
  }
  return toIso(y, m, due, calendar);
}

/** How a term reads in a list: "Net 30", or what a day-of-month term does. */
export function describeTerm(term: PaymentTermRule): string {
  if (term.kind === "days") return term.days === 0 ? "Due the day it is dated" : `${term.days} days after the date`;
  const day = term.day_of_month === 31 ? "the last day of the month" : `day ${term.day_of_month} of the month`;
  return term.next_month_within ? `Due ${day}; the next month when dated within ${term.next_month_within} days of it` : `Due ${day}`;
}

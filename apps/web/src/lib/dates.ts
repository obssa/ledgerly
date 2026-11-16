/*
 * "Today" as a date (YYYY-MM-DD) in a time zone. `new Date().toISOString()` is the UTC date, which in Addis Ababa
 * (UTC+3) is still yesterday for the first three hours of every day: default periods then stopped at yesterday and
 * date fields offered yesterday. The API decides with the company's own today (CurrentCompany::today()); pages use the
 * company's time zone, and browser forms the launch one, so the page rendered on the server and in the browser agree.
 */

export const DEFAULT_TIME_ZONE = "Africa/Addis_Ababa";

/** Today in the time zone (an IANA name such as Africa/Nairobi); an unknown or empty one falls back to the default. */
export function todayIn(timeZone?: string | null, now: Date = new Date()): string {
  const format = (zone: string) => new Intl.DateTimeFormat("en-CA", { timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit" }).format(now);
  try {
    return format(timeZone || DEFAULT_TIME_ZONE);
  } catch {
    return format(DEFAULT_TIME_ZONE);
  }
}

/**
 * Ethiopian calendar (Amete Mihret era) to and from Gregorian, by Julian Day Number: exact integer maths, the same as
 * apps/api/app/Support/EthiopianCalendar.php. Twelve months of 30 days plus Pagume (month 13) of 5 days, 6 when the
 * Ethiopian year divided by 4 leaves 3. Dates are always stored in Gregorian ("YYYY-MM-DD"); this is for display and
 * for period boundaries.
 */
export const ETHIOPIAN_MONTHS = ["Meskerem", "Tikimt", "Hidar", "Tahsas", "Tir", "Yekatit", "Megabit", "Miazia", "Ginbot", "Sene", "Hamle", "Nehase", "Pagume"] as const;

const EPOCH = 1723856; // Julian Day Number of the day before 1 Meskerem, year 0

export interface EthiopianDate { year: number; month: number; day: number }

const div = (a: number, b: number) => Math.floor(a / b);

function julianDay(iso: string): number {
  const [y, m, d] = iso.split("-").map(Number);
  const a = div(14 - m, 12), yy = y + 4800 - a, mm = m + 12 * a - 3;
  return d + div(153 * mm + 2, 5) + 365 * yy + div(yy, 4) - div(yy, 100) + div(yy, 400) - 32045;
}

/** "2026-11-25" → { year: 2019, month: 3, day: 16 } (Hidar 16, 2019). */
export function fromGregorian(iso: string): EthiopianDate {
  const jdn = julianDay(iso);
  const r = (jdn - EPOCH) % 1461;
  const n = (r % 365) + 365 * div(r, 1460);
  return { year: 4 * div(jdn - EPOCH, 1461) + div(r, 365) - div(r, 1460), month: div(n, 30) + 1, day: (n % 30) + 1 };
}

export function daysInMonth(year: number, month: number): number {
  return month <= 12 ? 30 : year % 4 === 3 ? 6 : 5;
}

/** Ethiopian year, month (1-13), day → the Gregorian "YYYY-MM-DD". */
export function toGregorian(year: number, month: number, day: number): string {
  if (month < 1 || month > 13 || day < 1 || day > daysInMonth(year, month)) throw new RangeError(`Not an Ethiopian date: ${year}-${month}-${day}`);
  const jdn = EPOCH + 365 + 365 * (year - 1) + div(year, 4) + 30 * month + day - 31;
  // Julian Day Number to Gregorian (Fliegel and Van Flandern).
  let l = jdn + 68569;
  const n = div(4 * l, 146097);
  l -= div(146097 * n + 3, 4);
  const i = div(4000 * (l + 1), 1461001);
  l = l - div(1461 * i, 4) + 31;
  const j = div(80 * l, 2447);
  const d = l - div(2447 * j, 80);
  l = div(j, 11);
  const m = j + 2 - 12 * l;
  const y = 100 * (n - 49) + i + l;
  return `${String(y).padStart(4, "0")}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
}

/** "Hidar 16, 2019": English month names, Western digits. */
export function formatEthiopian(iso: string): string {
  const e = fromGregorian(iso);
  return `${ETHIOPIAN_MONTHS[e.month - 1]} ${e.day}, ${e.year}`;
}

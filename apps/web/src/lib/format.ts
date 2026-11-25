import { parse, toString, type Scaled } from "./decimal";

/** "501745.00" → "501,745.00": always 2 decimals, grouping only, no float conversion. */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- currency kept in the signature for call sites
export function formatAmount(value: string | Scaled, _currency?: string): string {
  const scaled = typeof value === "bigint" ? value : parse(value);
  const [whole, frac] = toString(scaled).split(".");
  const negative = whole.startsWith("-");
  const digits = negative ? whole.slice(1) : whole;
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${negative ? "−" : ""}${grouped}.${frac}`;
}

export function formatMoney(value: string | Scaled, currency: string): string {
  return `${currency} ${formatAmount(value, currency)}`;
}

/** Gregorian → Ethiopian calendar date, for companies that choose it. English month names and digits only. */
export function toEthiopian(date: Date): { year: number; month: number; day: number; label: string } {
  const months = ["Meskerem", "Tikimt", "Hidar", "Tahsas", "Tir", "Yekatit", "Megabit", "Miazia", "Ginbot", "Sene", "Hamle", "Nehase", "Pagume"];
  // Julian Day Number arithmetic (Amete Mihret era; epoch JDN 1724221).
  const y = date.getUTCFullYear(), m = date.getUTCMonth() + 1, d = date.getUTCDate();
  const a = Math.floor((14 - m) / 12), yy = y + 4800 - a, mm = m + 12 * a - 3;
  const jdn = d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
  const r = (jdn - 1723856) % 1461;
  const n = (r % 365) + 365 * Math.floor(r / 1460);
  const year = 4 * Math.floor((jdn - 1723856) / 1461) + Math.floor(r / 365) - Math.floor(r / 1460);
  const month = Math.floor(n / 30) + 1;
  const day = (n % 30) + 1;
  return { year, month, day, label: `${months[month - 1]} ${day}, ${year}` };
}

/**
 * A day written out in the company's own calendar (Settings, Calendar), and only that one: "Wednesday, 25 November
 * 2026" for Gregorian, "Wednesday, Hidar 16, 2019" for Ethiopian. The date is a UTC midnight, as the API's dates are.
 */
export function formatLongDate(date: Date, calendar: "gregorian" | "ethiopian"): string {
  const weekday = date.toLocaleDateString("en-GB", { weekday: "long", timeZone: "UTC" });
  if (calendar === "ethiopian") return `${weekday}, ${toEthiopian(date).label}`;
  return date.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
}

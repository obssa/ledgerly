import { CURRENCY_DECIMALS, parse, roundToCurrency, toString, type Scaled } from "./decimal";

/** "501745.0000" → "501,745.00" (grouping only; no float conversion). */
export function formatAmount(value: string | Scaled, currency: string): string {
  const scaled = typeof value === "bigint" ? value : parse(value);
  const decimals = CURRENCY_DECIMALS[currency] ?? 2;
  const [whole, frac] = toString(roundToCurrency(scaled, currency)).split(".");
  const negative = whole.startsWith("-");
  const digits = negative ? whole.slice(1) : whole;
  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${negative ? "−" : ""}${grouped}${decimals > 0 ? "." + frac.slice(0, decimals) : ""}`;
}

export function formatMoney(value: string | Scaled, currency: string): string {
  return `${currency} ${formatAmount(value, currency)}`;
}

/** Gregorian → Ethiopian calendar date (Amharic month names), for companies that choose it. */
export function toEthiopian(date: Date): { year: number; month: number; day: number; label: string } {
  const months = ["መስከረም", "ጥቅምት", "ኅዳር", "ታኅሣሥ", "ጥር", "የካቲት", "መጋቢት", "ሚያዝያ", "ግንቦት", "ሰኔ", "ሐምሌ", "ነሐሴ", "ጳጉሜ"];
  // Julian Day Number arithmetic (Amete Mihret era; epoch JDN 1724221).
  const y = date.getUTCFullYear(), m = date.getUTCMonth() + 1, d = date.getUTCDate();
  const a = Math.floor((14 - m) / 12), yy = y + 4800 - a, mm = m + 12 * a - 3;
  const jdn = d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
  const r = (jdn - 1723856) % 1461;
  const n = (r % 365) + 365 * Math.floor(r / 1460);
  const year = 4 * Math.floor((jdn - 1723856) / 1461) + Math.floor(r / 365) - Math.floor(r / 1460);
  const month = Math.floor(n / 30) + 1;
  const day = (n % 30) + 1;
  return { year, month, day, label: `${months[month - 1]} ${day}, ${year} ዓ.ም` };
}

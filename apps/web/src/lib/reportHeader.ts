import type { Cell } from "./csv";
import { formatDay, type DateWords } from "./periods";

/**
 * What every report says about itself, as QuickBooks' report header and footer do: whose books (company name, and the
 * legal name when it differs), which report, which period or date, any filter narrowing it; and at the foot the basis,
 * the currency, and when and by whom it was prepared. The screen, the printout and the CSV/Excel files say the same.
 */
export interface ReportIdentity {
  company: string;
  legalName?: string | null;
  title: string;
  /** "1 July 2026 – 26 November 2026", "As of 26 November 2026", "All dates". */
  period: string;
  filters?: string[];
  basis?: "accrual" | "cash" | null;
  currency?: string | null;
  prepared: string;
}

/** "Wednesday, 26 November 2026, 10:15" in the company's time zone (and calendar). */
export function preparedLabel(now: Date, timeZone: string, calendar: DateWords, by?: string | null): string {
  const parts = Object.fromEntries(new Intl.DateTimeFormat("en-GB", { timeZone, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", weekday: "long", hour12: false })
    .formatToParts(now).map((p) => [p.type, p.value]));
  const iso = `${parts.year}-${parts.month}-${parts.day}`;
  const hour = parts.hour === "24" ? "00" : parts.hour;
  return `Prepared ${parts.weekday}, ${formatDay(iso, calendar)}, ${hour}:${parts.minute}${by ? ` by ${by}` : ""}`;
}

/** The line under the report: basis and currency, then when it was prepared. */
export function footerLine(h: ReportIdentity): string {
  return [h.basis ? (h.basis === "cash" ? "Cash basis" : "Accrual basis") : null, h.currency ? `Amounts in ${h.currency}` : null, h.prepared]
    .filter(Boolean).join(" · ");
}

/** The rows above a CSV or Excel file's column headings: company, legal name, report, period, filters, then a blank row. */
export function headerRows(h: ReportIdentity): Cell[][] {
  return [
    [h.company],
    ...(h.legalName && h.legalName !== h.company ? [[h.legalName]] : []),
    [h.title],
    [h.period],
    ...(h.filters ?? []).map((f) => [f]),
    [],
  ];
}

/** The rows below a file's figures: a blank row, then the footer line. */
export function footerRows(h: ReportIdentity): Cell[][] {
  return [[], [footerLine(h)]];
}

/** A file name that says what it is: "Abyssinia Coffee – Profit and loss – 1 July 2026 – 26 November 2026.xlsx". */
export function exportFileName(h: Pick<ReportIdentity, "company" | "title" | "period">, ext: string): string {
  const clean = (s: string) => s.replace(/[\\/:*?"<>|\r\n]+/g, " ").replace(/\s+/g, " ").trim();
  return `${clean(h.company)} – ${clean(h.title)} – ${clean(h.period)}.${ext}`.slice(0, 180);
}

/**
 * A report's columns, as QuickBooks offers them under Customize, Columns: each report has a catalogue of fields every
 * row carries; the reader ticks which to show and moves them left or right. The choice lives in the URL (`cols`, in
 * order), so a report is shared, saved, printed and exported as shown. Used by the transaction reports
 * (lib/transactionColumns.ts) and the detail reports (ageing detail, general ledger, statements).
 */
export type Kind = "date" | "text" | "money" | "count" | "rate";
export type ColumnDef<K extends string = string> = { key: K; label: string; group: string; kind: Kind };

type Query = Record<string, string | string[] | undefined>;
const list = (v: string | string[] | undefined) => (Array.isArray(v) ? v : v ? v.split(",") : []);

/**
 * The columns shown, from the URL. `cols` keeps its order; a column ticked in Customize (sent as `col`, in catalogue
 * order, with `customize=1`) is added at the end, an unticked one removed; unknown keys are dropped. None: the defaults.
 */
export function chooseColumns<K extends string>(q: Query, defs: readonly ColumnDef<K>[], defaults: readonly K[]): K[] {
  const keys = new Set<string>(defs.map((c) => c.key));
  const known = (k: string): k is K => keys.has(k);
  const order = list(q.cols).filter(known);
  const ticked = q.customize === "1" ? list(q.col).filter(known) : null;
  let cols: K[] = order.length > 0 ? order : [...defaults];
  if (ticked) cols = [...cols.filter((c) => ticked.includes(c)), ...ticked.filter((c) => !cols.includes(c))];
  return cols.length > 0 ? [...new Set(cols)] : [...defaults];
}

/** Whether the reader chose the columns (otherwise the report's standard ones show). */
export const chosenColumns = (q: Query) => list(q.cols).length > 0 || q.customize === "1";

/** One column moved left (-1) or right (+1). */
export function moveColumn<K extends string>(cols: K[], key: K, by: -1 | 1): K[] {
  const i = cols.indexOf(key), j = i + by;
  if (i < 0 || j < 0 || j >= cols.length) return cols;
  const next = [...cols];
  [next[i], next[j]] = [next[j], next[i]];
  return next;
}

/**
 * The standard columns without the foreign-currency ones when no row is in another currency: QuickBooks shows a
 * currency only where it differs from the company's. Columns the reader chose are kept as chosen.
 */
export function withoutUnusedForeign<K extends string>(cols: K[], rows: readonly { currency?: string | null }[], q: Query, foreign: readonly K[]): K[] {
  if (chosenColumns(q) || rows.some((r) => r.currency)) return cols;
  const kept = cols.filter((c) => !foreign.includes(c));
  return kept.length > 0 ? kept : cols;
}

/** A column holding a date ("date", "due_date", "moved_on") or a moment ("created_at"): written the company's way. */
export const isDateKey = (key: string) => /^(date|.+_date|.+_on)$/.test(key);
export const isMomentKey = (key: string) => /_at$/.test(key);

export const rightAligned = (kind: Kind) => kind === "money" || kind === "count" || kind === "rate";

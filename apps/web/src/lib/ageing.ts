import { parse, toString } from "./decimal";
import type { ColumnDef } from "./columns";
import type { ArAgeing } from "./api";

/**
 * The AR and AP ageing, as QuickBooks offers them: the ageing periods (days per period, number of periods), and the
 * detail's columns (Customize) and grouping (by ageing period, QuickBooks' own, or by customer or supplier).
 */
export const DEFAULT_INTERVAL = 30;
export const DEFAULT_PERIODS = 4;
export const DEFAULT_BUCKETS = ["current", "days_1_30", "days_31_60", "days_61_90", "over_90"];

type Query = Record<string, string | string[] | undefined>;

/** Days per ageing period (1 to 365) and number of periods (2 to 12), from the URL; the API checks the same limits. */
export function pickPeriods(q: Query): { interval: number; periods: number } {
  const int = (v: unknown, min: number, max: number, fallback: number) => {
    const n = typeof v === "string" && /^\d{1,3}$/.test(v) ? Number(v) : NaN;
    return n >= min && n <= max ? n : fallback;
  };
  return { interval: int(q.interval, 1, 365, DEFAULT_INTERVAL), periods: int(q.periods, 2, 12, DEFAULT_PERIODS) };
}

/** What a period is called: Current, 1–30 days, Over 90 days. */
export function bucketLabel(key: string): string {
  if (key === "current") return "Current";
  const days = /^days_(\d+)_(\d+)$/.exec(key);
  if (days) return `${days[1]}–${days[2]} days`;
  const over = /^over_(\d+)$/.exec(key);
  return over ? `Over ${over[1]} days` : key;
}

export type AgeRow = {
  id: string; contact_id: string; date: string; type: string; number: string | null; reference: string | null; memo: string | null; name: string;
  due_date: string | null; days_past_due: number | null; age: string; amount: string; open_balance: string; foreign_amount: string | null;
  foreign_open_balance: string | null; currency: string | null; exchange_rate: string | null; location: string | null; created_by: string | null; posted_by: string | null;
};
export type AgeColumn = Exclude<keyof AgeRow, "id" | "contact_id">;

export const AGE_COLUMNS: ColumnDef<AgeColumn>[] = [
  { key: "date", label: "Date", group: "Transaction", kind: "date" },
  { key: "type", label: "Transaction type", group: "Transaction", kind: "text" },
  { key: "number", label: "No.", group: "Transaction", kind: "text" },
  { key: "reference", label: "Reference", group: "Transaction", kind: "text" },
  { key: "memo", label: "Memo", group: "Transaction", kind: "text" },
  { key: "name", label: "Name", group: "Customer or supplier", kind: "text" },
  { key: "due_date", label: "Due date", group: "Dates", kind: "date" },
  { key: "days_past_due", label: "Past due (days)", group: "Dates", kind: "count" },
  { key: "age", label: "Ageing period", group: "Dates", kind: "text" },
  { key: "amount", label: "Amount", group: "Amounts", kind: "money" },
  { key: "open_balance", label: "Open balance", group: "Amounts", kind: "money" },
  { key: "foreign_amount", label: "Foreign amount", group: "Foreign currency", kind: "money" },
  { key: "foreign_open_balance", label: "Foreign open balance", group: "Foreign currency", kind: "money" },
  { key: "currency", label: "Currency", group: "Foreign currency", kind: "text" },
  { key: "exchange_rate", label: "Exchange rate", group: "Foreign currency", kind: "rate" },
  { key: "location", label: "Location", group: "Tracking", kind: "text" },
  { key: "created_by", label: "Created by", group: "People", kind: "text" },
  { key: "posted_by", label: "Posted by", group: "People", kind: "text" },
];
/** QuickBooks' ageing detail: date, type, number, name, due date, past due, amount, open balance (and the foreign ones). */
export const AGE_DEFAULTS: AgeColumn[] = ["date", "type", "number", "name", "due_date", "days_past_due", "amount", "open_balance", "foreign_open_balance", "currency"];
export const AGE_FOREIGN: AgeColumn[] = ["foreign_amount", "foreign_open_balance", "currency", "exchange_rate"];
export const AGE_GROUPS = [["age", "Ageing period"], ["name", "Customer or supplier"]] as const;
export type AgeGroup = (typeof AGE_GROUPS)[number][0];
export const pickAgeGroup = (q: Query): AgeGroup => (q.group === "name" ? "name" : "age");

/** Each open document as a row of the detail; foreign fields only for a document in another currency. */
export function ageRows(ageing: ArAgeing): AgeRow[] {
  const base = ageing.currency ?? "";
  const names = new Map(ageing.rows.map((r) => [r.contact_id, r.name]));
  return (ageing.documents ?? []).map((d) => {
    const foreign = d.currency !== base;
    return {
      id: d.id, contact_id: d.contact_id, date: d.issue_date, type: d.type ?? "invoice", number: d.number, reference: d.reference, memo: d.memo ?? null,
      name: names.get(d.contact_id) ?? "", due_date: d.due_date, days_past_due: d.days_late > 0 ? d.days_late : null, age: d.bucket,
      amount: d.base_total ?? d.amount, open_balance: d.amount,
      foreign_amount: foreign ? d.total : null, foreign_open_balance: foreign ? d.amount_due : null,
      currency: foreign ? d.currency : null, exchange_rate: foreign ? d.exchange_rate ?? null : null,
      location: d.location ?? null, created_by: d.created_by ?? null, posted_by: d.posted_by ?? null,
    };
  });
}

/** The sum of an amount column (foreign ones only when the rows share one currency). */
export function sumOf(rows: AgeRow[], key: "amount" | "open_balance" | "foreign_amount" | "foreign_open_balance"): string | null {
  if (key.startsWith("foreign") && new Set(rows.map((r) => r.currency)).size !== 1) return null;
  return toString(rows.reduce((s, r) => (r[key] ? s + parse(r[key]!) : s), 0n));
}

export type AgeGroupBlock = { key: string; label: string; contactId: string | null; rows: AgeRow[]; credits: string | null; total: string | null };

/**
 * The detail in groups. By ageing period: each period, oldest last, then the credit not yet used per party. By party:
 * each customer or supplier with their documents, credit not used, and what they owe (their ledger balance).
 */
export function groupAgeRows(ageing: ArAgeing, rows: AgeRow[], group: AgeGroup, creditsLabel: string): AgeGroupBlock[] {
  if (group === "name") {
    return ageing.rows.map((r) => ({ key: r.contact_id, label: r.name, contactId: r.contact_id, rows: rows.filter((d) => d.contact_id === r.contact_id),
      credits: r.credits !== "0.00" ? r.credits : null, total: r.total }))
      .filter((g) => g.rows.length > 0 || g.credits !== null);
  }
  const buckets = ageing.buckets ?? DEFAULT_BUCKETS;
  const out: AgeGroupBlock[] = buckets.map((b) => {
    const list = rows.filter((d) => d.age === b);
    return { key: b, label: bucketLabel(b), contactId: null, rows: list, credits: null, total: sumOf(list, "open_balance") };
  }).filter((g) => g.rows.length > 0);
  const credits = ageing.rows.filter((r) => r.credits !== "0.00");
  if (credits.length > 0) {
    out.push({ key: "credits", label: creditsLabel, contactId: null, credits: null,
      rows: credits.map((r) => ({ id: `credit-${r.contact_id}`, contact_id: r.contact_id, date: "", type: "credit", number: null, reference: null, memo: null, name: r.name,
        due_date: null, days_past_due: null, age: "credits", amount: "", open_balance: r.credits, foreign_amount: null, foreign_open_balance: null,
        currency: null, exchange_rate: null, location: null, created_by: null, posted_by: null })),
      total: toString(credits.reduce((s, r) => s + parse(r.credits), 0n)) });
  }
  return out;
}

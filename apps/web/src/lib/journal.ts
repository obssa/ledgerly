import { parse, toString } from "./decimal";

/* Module 6, manual journals: the lines the form keeps, their totals, and what is sent. The API checks everything again. */

export interface JournalLineInput {
  account_id: string;
  debit: string;
  credit: string;
  description: string;
  location_id?: string;
  class_id?: string;
  project_id?: string;
}

export const emptyLine = (): JournalLineInput => ({ account_id: "", debit: "", credit: "", description: "" });

const clean = (v: string) => v.replace(/[,\s]/g, "");
const amount = (v: string): bigint => (/^\d+(\.\d+)?$/.test(clean(v)) ? parse(clean(v)) : 0n);

/** Debits, credits and what is left to balance (debits minus credits). */
export function journalTotals(lines: JournalLineInput[]): { debit: string; credit: string; difference: string; balanced: boolean } {
  let d = 0n;
  let c = 0n;
  for (const l of lines) {
    d += amount(l.debit);
    c += amount(l.credit);
  }
  return { debit: toString(d), credit: toString(c), difference: toString(d - c), balanced: d === c && d > 0n };
}

/** The request body from the form: the date and memo, the lines that have an account and an amount, and when it reverses. */
export function journalBody(form: FormData): { date: string; memo: string; lines: Record<string, string>[]; reverse_on?: string } {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  let raw: unknown = [];
  try {
    raw = JSON.parse(value("lines") || "[]");
  } catch {
    raw = [];
  }
  const lines = (Array.isArray(raw) ? raw : []).flatMap((l: Partial<JournalLineInput>) => {
    const debit = clean(String(l.debit ?? ""));
    const credit = clean(String(l.credit ?? ""));
    if (!l.account_id || (debit === "" && credit === "")) return [];
    const out: Record<string, string> = { account_id: String(l.account_id) };
    if (debit !== "") out.debit = debit;
    if (credit !== "") out.credit = credit;
    for (const k of ["description", "location_id", "class_id", "project_id"] as const) if (l[k]) out[k] = String(l[k]).trim();
    return [out];
  });
  const reverseOn = value("reverse_on");
  return { date: value("date"), memo: value("memo"), lines, ...(reverseOn ? { reverse_on: reverseOn } : {}) };
}

export const FREQUENCIES = [["monthly", "Every month"], ["quarterly", "Every quarter"], ["yearly", "Every year"]] as const;

/** A recurring journal's body: its name and schedule, with the memo and lines a journal has. */
export function recurringBody(form: FormData): { name: string; memo: string; frequency: string; next_on: string; ends_on: string | null; lines: Record<string, string>[] } {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  const { memo, lines } = journalBody(form);
  return { name: value("name"), memo, frequency: value("frequency"), next_on: value("next_on"), ends_on: value("ends_on") || null, lines };
}

/** Accounts a manual journal can use: active, in the company's currency, not kept by documents (the API's KEPT lists). */
const KEPT_KINDS = ["receivable", "payable", "inventory"];
const KEPT_KEYS = ["accounts_receivable", "accounts_payable", "inventory", "grni", "undeposited_funds", "retained_earnings"];

export function journalAccounts<T extends { is_active: boolean; subtype?: string | null; system_key: string | null; currency: string | null; code: string }>(accounts: T[], base: string): T[] {
  return accounts
    .filter((a) => a.is_active && !KEPT_KINDS.includes(a.subtype ?? "") && !KEPT_KEYS.includes(a.system_key ?? "") && (a.currency === null || a.currency === base))
    .sort((a, b) => a.code.localeCompare(b.code));
}

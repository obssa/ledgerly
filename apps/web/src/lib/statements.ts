import type { Account } from "./api";

/* Module 5, statements: what the review screen needs that is not on the server. The server checks everything again. */

export type StatementStatus = "imported" | "matched" | "categorised" | "excluded" | "reconciled";

export const STATEMENT_TABS: { status: StatementStatus | "all"; label: string }[] = [
  { status: "imported", label: "To review" },
  { status: "matched", label: "Matched" },
  { status: "categorised", label: "Categorised" },
  { status: "excluded", label: "Excluded" },
  { status: "all", label: "All" },
];

/** A statement file larger than this is refused before it is sent (Server Functions take 1 MB; a year of lines fits). */
export const MAX_STATEMENT_BYTES = 900_000;

/** The tab in the address bar, or "to review". */
export function statementTab(value: unknown): StatementStatus | "all" {
  return STATEMENT_TABS.some((t) => t.status === value) ? (value as StatementStatus | "all") : "imported";
}

/** Kept by their own documents (invoices, bills, payments, stock), so never a category for a bank line. */
const OWN_DOCUMENTS = ["receivable", "payable", "inventory", "opening_balance", "retained_earnings"];

/** Accounts a statement line can be categorised to: active, not the bank account itself, not kept by documents. */
export function categorisable(accounts: Account[], bankAccountId: string): Account[] {
  return accounts
    .filter((a) => a.is_active && a.id !== bankAccountId && !OWN_DOCUMENTS.includes(a.subtype ?? ""))
    .sort((a, b) => a.code.localeCompare(b.code));
}

/** The statement from the form: an uploaded CSV file, or text pasted into the box. */
export async function statementFrom(form: FormData): Promise<{ csv: string; fileName?: string } | { error: string }> {
  const file = form.get("file");
  if (file instanceof File && file.size > 0) {
    if (file.size > MAX_STATEMENT_BYTES) return { error: "The file is larger than 900 KB: export one month at a time." };
    return { csv: await file.text(), fileName: file.name.slice(0, 200) };
  }
  const pasted = form.get("csv");
  if (typeof pasted === "string" && pasted.trim() !== "") {
    if (pasted.length > MAX_STATEMENT_BYTES) return { error: "The statement is larger than 900 KB: paste one month at a time." };
    return { csv: pasted };
  }
  return { error: "Choose the statement file your bank or wallet exported (CSV, OFX, MT940 or camt.053), or paste its lines." };
}

/** "+1,250.00" for money in, "−250.00" for money out, as a statement shows them. */
export function signed(amount: string): { text: string; direction: "in" | "out" } {
  const out = amount.trim().startsWith("-");
  const digits = out ? amount.trim().slice(1) : amount.trim();
  const [whole, cents = "00"] = digits.split(".");
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return { text: `${out ? "−" : "+"}${grouped}.${cents.padEnd(2, "0").slice(0, 2)}`, direction: out ? "out" : "in" };
}

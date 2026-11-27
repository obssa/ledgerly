import { parse } from "./decimal";

/*
 * What a sales or purchase document's status is called in lists and on its page, in QuickBooks' words: "Overdue 12
 * days", "Due in 30 days", "Due today", "Partially paid", "Paid"; credits are "Unapplied", "Partially applied" or
 * "Applied". Dates are the company's own today, as YYYY-MM-DD.
 */

export type Tone = "neutral" | "good" | "warn" | "bad" | "brand";
export type DocStatus = { label: string; tone: Tone };

export type StatusDoc = {
  status: string;
  due_date: string | null;
  total: string;
  amount_due: string;
};

/** Whole days from one date to another (negative when `to` is earlier). Both are YYYY-MM-DD. */
export function daysBetween(from: string, to: string): number {
  const day = (iso: string) => {
    const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
    return Date.UTC(y, m - 1, d) / 86_400_000;
  };
  return Math.round(day(to) - day(from));
}

const plural = (n: number) => `${n} day${n === 1 ? "" : "s"}`;

/** The states before and after a document is open, the same for every kind. */
function closed(status: string): DocStatus | null {
  if (status === "draft") return { label: "Draft", tone: "neutral" };
  if (status === "awaiting_approval") return { label: "Awaiting approval", tone: "warn" };
  if (status === "void") return { label: "Void", tone: "neutral" };
  return null;
}

/** An invoice or a bill: paid, or how long until (or since) it falls due. */
export function paymentStatus(doc: StatusDoc, today: string): DocStatus {
  const fixed = closed(doc.status);
  if (fixed) return fixed;
  const open = parse(doc.amount_due);
  if (doc.status === "paid" || open <= 0n) return { label: "Paid", tone: "good" };
  const partly = doc.status === "part_paid" || open < parse(doc.total);
  if (doc.due_date) {
    const days = daysBetween(today, doc.due_date);
    if (days < 0) return { label: `Overdue ${plural(-days)}${partly ? " · partially paid" : ""}`, tone: "bad" };
    if (!partly) return days === 0 ? { label: "Due today", tone: "warn" } : { label: `Due in ${plural(days)}`, tone: "brand" };
  }
  return partly ? { label: "Partially paid", tone: "warn" } : { label: "Open", tone: "brand" };
}

/** A credit note or a supplier credit: how much of it has been used. */
export function creditStatus(doc: StatusDoc): DocStatus {
  const fixed = closed(doc.status);
  if (fixed) return fixed;
  const open = parse(doc.amount_due);
  if (doc.status === "paid" || open <= 0n) return { label: "Applied", tone: "good" };
  return open < parse(doc.total) ? { label: "Partially applied", tone: "warn" } : { label: "Unapplied", tone: "brand" };
}

const METHODS: Record<string, string> = {
  bank_transfer: "Bank transfer", cash: "Cash", cheque: "Cheque", mobile_money: "Mobile money", card: "Card", other: "Other",
};

/** A payment method as people write it: "Bank transfer", not "bank_transfer". */
export function methodLabel(method: string): string {
  if (METHODS[method]) return METHODS[method];
  const words = method.replaceAll("_", " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** "1 invoice", "3 bills", or "—" when a payment was applied to nothing (kept as credit). */
export function appliedTo(count: number | null | undefined, one: string): string {
  const n = count ?? 0;
  return n === 0 ? "—" : `${n} ${one}${n === 1 ? "" : "s"}`;
}

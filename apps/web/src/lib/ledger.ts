import { parse, toString } from "./decimal";

/* Module 6, ledger reports: how balances read, and the period a report opens on. */

/** A balance kept as debit minus credit, read the way a ledger prints it: the amount and Dr or Cr. */
export function drCr(debitMinusCredit: string): { amount: string; side: "Dr" | "Cr" | "" } {
  const v = parse(debitMinusCredit.trim() || "0");
  if (v === 0n) return { amount: "0.00", side: "" };
  return v > 0n ? { amount: toString(v), side: "Dr" } : { amount: toString(-v), side: "Cr" };
}

/** Debits and credits of a trial balance, and whether they agree. */
export function trialTotals(rows: { debit: string; credit: string }[]): { debit: string; credit: string; balanced: boolean } {
  let d = 0n;
  let c = 0n;
  for (const r of rows) {
    d += parse(r.debit || "0");
    c += parse(r.credit || "0");
  }
  return { debit: toString(d), credit: toString(c), balanced: d === c };
}

/** The period a ledger report opens on when none is asked for: the start of this year to today. */
export function defaultPeriod(today: string): { from: string; to: string } {
  return { from: `${today.slice(0, 4)}-01-01`, to: today };
}

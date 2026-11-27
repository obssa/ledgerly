import { parse, parseRate, toString } from "./decimal";

/**
 * The payment forms (received and made): header fields, and one allocation field per open invoice or bill named
 * "alloc:<id>". Blank or zero allocations are left out; amounts keep their decimal string, thousands separators removed.
 */

const clean = (v: FormDataEntryValue | null): string => (typeof v === "string" ? v.replace(/[,\s]/g, "") : "");
const blank = (v: string) => v === "" || /^0*(\.0*)?$/.test(v);

export function paymentBody(form: FormData, settles: "invoice" | "bill" = "invoice"): Record<string, unknown> {
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };
  const body: Record<string, unknown> = {
    contact_id: value("contact_id"),
    account_id: value("account_id"),
    payment_date: value("payment_date"),
    method: value("method"),
    currency: value("currency"),
    amount: clean(form.get("amount")),
  };
  for (const name of ["reference", "withholding_certificate"]) if (value(name) !== "") body[name] = value(name);
  if (body.method === "cheque" && value("cheque_number") !== "") body.cheque_number = value("cheque_number");
  for (const name of ["fee", "withholding", "fx_rate", "account_amount"]) if (!blank(clean(form.get(name)))) body[name] = clean(form.get(name));

  const allocations: Record<string, string>[] = [];
  for (const [key, v] of form.entries()) {
    if (!key.startsWith("alloc:")) continue;
    const amount = clean(v);
    if (!blank(amount)) allocations.push({ [`${settles}_id`]: key.slice(6), amount });
  }
  if (allocations.length > 0) body.allocations = allocations;
  return body;
}

/**
 * The money accounts a payment in `currency` may go through, as QuickBooks allows: one in that currency, or one in the
 * company's (a dollar invoice paid into a birr account); those in the payment's own currency first.
 */
export function paymentAccounts<T extends { currency: string | null }>(accounts: T[], currency: string, base: string): T[] {
  const held = (a: T) => a.currency ?? base;
  return accounts.filter((a) => held(a) === currency || held(a) === base).sort((a, b) => Number(held(b) === currency) - Number(held(a) === currency));
}

/** The currencies a contact may be paid in: their own, any of their open documents', and the company's. */
export function paymentCurrencies(own: string | null | undefined, documents: readonly { currency: string }[], base: string, enabled: readonly string[]): string[] {
  const list = [own && (own === base || enabled.includes(own)) ? own : null, ...documents.map((d) => d.currency), base].filter((c): c is string => Boolean(c));
  return [...new Set(list)];
}

/** The rate the bank gave: what the account moved, in the company's currency, for each unit of the payment's (6 decimals). */
export function bankRate(moved: string, amount: string): string | null {
  const m = safe(moved), a = safe(amount);
  if (m <= 0n || a <= 0n) return null;
  const micro = (m * 1_000_000n * 2n + a) / (2n * a);   // half-up, both in hundredths
  return `${micro / 1_000_000n}.${String(micro % 1_000_000n).padStart(6, "0")}`;
}

/**
 * Settles the oldest invoices first with what the payment brings in (amount received + tax withheld).
 * @param due amount still due per invoice, oldest first
 * @return the allocation per invoice, as decimal strings
 */
export function allocateOldestFirst(settles: string, due: string[]): string[] {
  let left = safe(settles);
  return due.map((d) => {
    const owed = safe(d);
    const take = left < owed ? left : owed;
    left -= take;
    return take > 0n ? toString(take) : "";
  });
}

/** Totals while typing: what the payment settles, what is allocated, and the customer credit left over. */
export function paymentTotals(amount: string, withholding: string, allocations: string[]): { settles: string; allocated: string; credit: string } {
  const settles = safe(amount) + safe(withholding);
  const allocated = allocations.reduce((s, a) => s + safe(a), 0n);
  return { settles: toString(settles), allocated: toString(allocated), credit: toString(settles - allocated) };
}

/**
 * Withholding is worked out on the price before VAT. The basis is the pre-VAT share of what is being paid on each bill:
 * allocation × subtotal ÷ total, in exact decimals (rounded down to the cent).
 */
export function withholdingBasis(allocations: string[], bills: { subtotal: string; total: string }[]): string {
  let basis = 0n;
  bills.forEach((b, i) => {
    const total = safe(b.total);
    if (total > 0n) basis += (safe(allocations[i] ?? "") * safe(b.subtotal)) / total;
  });
  return toString(basis);
}

/**
 * The amount to withhold the country pack suggests: the rate times the basis, once the basis is above the threshold
 * (half-cents round up). Empty below the threshold. The person paying confirms it; the API checks only exemptions.
 */
export function suggestedWithholding(rate: string | null, basis: string, above: string | null): string {
  if (rate === null || above === null || safe(basis) <= safe(above)) return "";
  let r: bigint;
  try {
    r = parseRate(rate); // six decimals: 0.03 is 30000n
  } catch {
    return "";
  }
  return toString((safe(basis) * r + 500_000n) / 1_000_000n);
}

function safe(value: string): bigint {
  try {
    const v = value.replace(/[,\s]/g, "");
    return v === "" ? 0n : parse(v);
  } catch {
    return 0n;
  }
}

/**
 * A payment recorded while invoices (or bills) are open must say what it settles: an empty Settle column would
 * leave them all unpaid and the money as credit. Keeping it all as credit is a choice made on purpose (a tick).
 * Returns what to tell the person, or null when the payment can be saved.
 */
export function settleProblem(settled: string[], openCount: number, keepAsCredit: boolean, kind: "invoice" | "bill"): string | null {
  if (openCount === 0 || keepAsCredit) return null;
  const any = settled.some((v) => /[1-9]/.test(v.replace(/[,\s]/g, "")));
  if (any) return null;
  return kind === "invoice"
    ? "Fill in the Settle column first: type how much of this payment goes to each invoice, or click \"Settle oldest first\". Left empty, no invoice would be marked as paid."
    : "Fill in the Pay column first: type how much of this payment goes to each bill, or click \"Spread the amount\". Left empty, no bill would be marked as paid.";
}

/** The Settle values of a submitted payment form (fields named alloc:<id>). */
export function settledIn(form: FormData): string[] {
  return [...form.entries()].filter(([k]) => k.startsWith("alloc:")).map(([, v]) => String(v));
}

import { parse, toString } from "./decimal";

/**
 * The receive-payment form: header fields, and one allocation field per open invoice named "alloc:<invoice id>".
 * Blank or zero allocations are left out; amounts keep their decimal string, thousands separators removed.
 */

const clean = (v: FormDataEntryValue | null): string => (typeof v === "string" ? v.replace(/[,\s]/g, "") : "");
const blank = (v: string) => v === "" || /^0*(\.0*)?$/.test(v);

export function paymentBody(form: FormData): Record<string, unknown> {
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
  for (const name of ["reference"]) if (value(name) !== "") body[name] = value(name);
  for (const name of ["fee", "withholding", "fx_rate"]) if (!blank(clean(form.get(name)))) body[name] = clean(form.get(name));

  const allocations: { invoice_id: string; amount: string }[] = [];
  for (const [key, v] of form.entries()) {
    if (!key.startsWith("alloc:")) continue;
    const amount = clean(v);
    if (!blank(amount)) allocations.push({ invoice_id: key.slice(6), amount });
  }
  if (allocations.length > 0) body.allocations = allocations;
  return body;
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

function safe(value: string): bigint {
  try {
    const v = value.replace(/[,\s]/g, "");
    return v === "" ? 0n : parse(v);
  } catch {
    return 0n;
  }
}

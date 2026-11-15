import { parse, toString } from "./decimal";

/**
 * The opening balances form: one debit and one credit field per account, named "debit:<account id>" and
 * "credit:<account id>". Amounts are decimal strings; thousands separators are allowed. Empty rows are left out.
 */

export interface OpeningLine {
  account_id: string;
  debit?: string;
  credit?: string;
}

const clean = (value: FormDataEntryValue | null): string => (typeof value === "string" ? value.replace(/[,\s]/g, "") : "");

/** The lines to send to PUT /opening-balances. A field that is not a number is sent as is, so the API names it. */
export function openingLines(form: FormData): OpeningLine[] {
  const ids = new Set<string>();
  for (const key of form.keys()) {
    const [side, id] = key.split(":");
    if ((side === "debit" || side === "credit") && id) ids.add(id);
  }
  const lines: OpeningLine[] = [];
  for (const id of ids) {
    const debit = clean(form.get(`debit:${id}`));
    const credit = clean(form.get(`credit:${id}`));
    if (isBlank(debit) && isBlank(credit)) continue;
    lines.push({ account_id: id, ...(isBlank(debit) ? {} : { debit }), ...(isBlank(credit) ? {} : { credit }) });
  }
  return lines;
}

/** Totals while typing. Fields that are not numbers yet count as zero. difference = debits − credits. */
export function openingTotals(values: { debit: string; credit: string }[]): { debit: string; credit: string; difference: string } {
  let debit = 0n;
  let credit = 0n;
  for (const v of values) {
    debit += safe(v.debit);
    credit += safe(v.credit);
  }
  return { debit: toString(debit), credit: toString(credit), difference: toString(debit - credit) };
}

function safe(value: string): bigint {
  try {
    return value.trim() === "" ? 0n : parse(value.replace(/[,\s]/g, ""));
  } catch {
    return 0n;
  }
}

/** Empty or zero: the row is not sent. */
export function isBlank(value: string): boolean {
  const v = value.replace(/[,\s]/g, "");
  return v === "" || /^0*(\.0*)?$/.test(v);
}

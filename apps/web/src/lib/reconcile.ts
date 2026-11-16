import { parse, toString } from "./decimal";

/* Module 5, reconciliation: the totals the workspace shows while ticking. The API works them out again on every save. */

export interface Tick {
  amount: string;
  cleared: boolean;
}

/** Opening + ticked entries against the statement's ending balance; finished when the difference is 0.00. */
export function reconcileTotals(opening: string, ending: string, lines: Tick[]): { clearedIn: string; clearedOut: string; clearedBalance: string; difference: string } {
  let inn = 0n;
  let out = 0n;
  for (const l of lines) {
    if (!l.cleared) continue;
    const a = parse(l.amount);
    if (a < 0n) out -= a;
    else inn += a;
  }
  const safe = (v: string) => (/^-?\d+(\.\d+)?$/.test(v.replace(/[,\s]/g, "")) ? parse(v.replace(/[,\s]/g, "")) : 0n);
  const balance = safe(opening) + inn - out;
  return { clearedIn: toString(inn), clearedOut: toString(out), clearedBalance: toString(balance), difference: toString(safe(ending) - balance) };
}

/** What the workspace form sends: the statement's date and ending balance, and the ticked entries. */
export function reconciliationBody(form: FormData): { statement_date?: string; ending_balance?: string; cleared: string[] } {
  const text = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" && v.trim() !== "" ? v.trim() : undefined;
  };
  const ending = text("ending_balance")?.replace(/[,\s]/g, "");
  return {
    ...(text("statement_date") ? { statement_date: text("statement_date") } : {}),
    ...(ending ? { ending_balance: ending } : {}),
    cleared: [...new Set(form.getAll("cleared").filter((v): v is string => typeof v === "string" && v !== ""))],
  };
}

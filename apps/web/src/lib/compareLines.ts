import type { BalanceSheet, CashFlowStatement, DirectCashFlow, ProfitAndLoss, TrialBalanceRow } from "./api";
import { amountChange, mergeRows, pctChange, shows, type Diff, type Line } from "./compare";
import type { Cell } from "./csv";

/*
 * The financial statements as lines with a value per period (the report's own period first, then each comparison):
 * the same lines on screen (CompareTable) and in the CSV and Excel files, so they always agree.
 */

const heading = (key: string, label: string, n: number): Line => ({ key: `h:${key}`, label, kind: "heading", values: Array(n).fill(null) });
const total = (key: string, label: string, values: (string | null)[], kind: Line["kind"] = "total"): Line => ({ key: `t:${key}`, label, kind, values });

/** The profit and loss: income, cost of goods sold, gross profit, expenses, net profit; other comprehensive income when any. */
export function profitAndLossLines(results: ProfitAndLoss[]): Line[] {
  const n = results.length;
  const out: Line[] = [];
  const section = (key: "income" | "cost_of_sales" | "expenses", title: string) => {
    out.push(heading(key, title, n));
    out.push(...mergeRows(results.map((r) => r.sections[key]), (r) => r.code, (r) => r.current, (r) => r.name, (r) => r.code));
    out.push(total(key, `Total ${title.toLowerCase()}`, results.map((r) => r.totals[key].current)));
  };
  section("income", "Income");
  section("cost_of_sales", "Cost of goods sold");
  out.push(total("gross_profit", "Gross profit", results.map((r) => r.totals.gross_profit.current), "key"));
  section("expenses", "Expenses");
  out.push(total("net_profit", "Net profit before tax", results.map((r) => r.totals.net_profit.current), "grand"));
  const oci = results.map((r) => r.totals.other_comprehensive_income?.current ?? null);
  if (oci.some((v) => v !== null && Number(v) !== 0)) {
    out.push(total("oci", "Other comprehensive income: revaluation surplus", oci, "key"));
    out.push(total("tci", "Total comprehensive income", results.map((r) => r.totals.total_comprehensive_income?.current ?? null), "grand"));
  }
  return out;
}

/** The balance sheet: assets, liabilities, equity, profit not yet closed, liabilities and equity. */
export function balanceSheetLines(results: BalanceSheet[]): Line[] {
  const n = results.length;
  const out: Line[] = [];
  for (const [key, title] of [["assets", "Assets"], ["liabilities", "Liabilities"], ["equity", "Equity"]] as const) {
    out.push(heading(key, title, n));
    const rows = mergeRows(results.map((r) => r.sections[key]), (r) => r.code, (r) => r.amount, (r) => r.name, (r) => r.code);
    out.push(...rows.sort((a, b) => (a.code ?? "").localeCompare(b.code ?? "")));
    out.push(total(key, `Total ${title.toLowerCase()}`, results.map((r) => r.equation[key])));
  }
  out.push({ key: "t:profit", label: "Profit not yet closed to retained earnings", kind: "row", values: results.map((r) => r.equation.profit_to_date) });
  out.push(total("le", "Liabilities and equity", results.map((r) => r.equation.liabilities_and_equity), "grand"));
  return out;
}

const CASH_SECTIONS = [["operating", "Operating activities"], ["investing", "Investing activities"], ["financing", "Financing activities"]] as const;

function cashFoot(results: { net_change: string; opening_cash: string; closing_cash: string }[]): Line[] {
  return [
    total("net_change", "Net change in cash", results.map((r) => r.net_change), "key"),
    { key: "t:opening", label: "Cash at the start", kind: "row", values: results.map((r) => r.opening_cash) },
    total("closing", "Cash at the end", results.map((r) => r.closing_cash), "grand"),
  ];
}

/** The cash flow statement, indirect method: net profit, adjustments and working capital, investing, financing. */
export function cashFlowLines(results: CashFlowStatement[]): Line[] {
  const n = results.length;
  const out: Line[] = [];
  for (const [key, title] of CASH_SECTIONS) {
    out.push(heading(key, title, n));
    if (key === "operating") out.push({ key: "r:net_profit", label: "Net profit for the period", kind: "row", values: results.map((r) => r.net_profit) });
    out.push(...mergeRows(results.map((r) => r.sections[key]), (r) => `${r.code}|${r.name}`, (r) => r.amount, (r) => r.name, (r) => r.code));
    out.push(total(key, `Cash from ${title.toLowerCase()}`, results.map((r) => r.totals[key])));
  }
  return [...out, ...cashFoot(results)];
}

/** The cash flow statement, direct method: what the cash accounts received and paid, by what it was for. */
export function directCashFlowLines(results: DirectCashFlow[]): Line[] {
  const n = results.length;
  const out: Line[] = [];
  const groups = { operating: ["customers", "suppliers", "employees", "taxes", "other"], investing: ["investing"], financing: ["financing"] } as const;
  for (const [key, title] of CASH_SECTIONS) {
    out.push(heading(key, title, n));
    const keys = groups[key] as readonly string[];
    out.push(...mergeRows(results.map((r) => r.lines.filter((l) => keys.includes(l.key))), (l) => l.key, (l) => l.amount, (l) => l.label));
    out.push(total(key, `Cash from ${title.toLowerCase()}`, results.map((r) => r.totals[key])));
  }
  return [...out, ...cashFoot(results)];
}

/** The trial balance: each account's debit and credit at each date (values: debit, credit, debit, credit …). */
export function trialBalanceLines(results: TrialBalanceRow[][]): { code: string; name: string; account_id: string; values: (string | null)[] }[] {
  const order: string[] = [];
  const first = new Map<string, TrialBalanceRow>();
  results.forEach((rows) => rows.forEach((r) => { if (!first.has(r.code)) { first.set(r.code, r); order.push(r.code); } }));
  const maps = results.map((rows) => new Map(rows.map((r) => [r.code, r])));
  return order.sort((a, b) => a.localeCompare(b)).map((code) => {
    const r = first.get(code)!;
    return { code, name: r.name, account_id: r.account_id, values: maps.flatMap((m) => [m.get(code)?.debit ?? null, m.get(code)?.credit ?? null]) };
  });
}

/** The lines as a file's rows: Code, Account, a column per period, then the change columns asked for. */
export function lineCells(lines: Line[], headers: string[], diff: Diff, extra?: { header: string; value: (l: Line) => string }): Cell[][] {
  const compared = headers.length > 1;
  const amount = compared && (diff === "amount" || diff === "both");
  const pct = compared && (diff === "pct" || diff === "both");
  const rows: Cell[][] = [["Code", "Account", ...headers, ...(extra ? [extra.header] : []), ...(amount ? ["Change"] : []), ...(pct ? ["% change"] : [])]];
  for (const l of lines.filter(shows)) {
    if (l.kind === "heading") { rows.push([null, l.label]); continue; }
    rows.push([l.code ?? null, l.label, ...l.values, ...(extra ? [extra.value(l)] : []), ...(amount ? [amountChange(l.values)] : []), ...(pct ? [pctChange(l.values)] : [])]);
  }
  return rows;
}

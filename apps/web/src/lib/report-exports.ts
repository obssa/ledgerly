import "server-only";
import { TX_REPORTS, column, pickColumns, pickSort, sortRows, statusLabel, totals, typeLabel, type TxColumn, type TxRow } from "./transactionColumns";
import { ledgerly } from "./api";
import { DISPLAY_BY, columnsRequest, percentOf, pickDisplayBy } from "./pnlColumns";
import type { Calendar } from "./periods";
import { formatDate, pickDateFormat } from "./dates";
import { periodLabel, previousPeriod, samePeriodLastYear } from "./reports";
import { shiftDay } from "./periods";
import { perUnit, percentChange } from "./decimal";
import { STATEMENT_DEFAULTS, statementColumns, statementRow } from "./ledgerColumns";
import { AGE_COLUMNS, AGE_DEFAULTS, AGE_FOREIGN, DEFAULT_BUCKETS, ageRows, bucketLabel, groupAgeRows, pickPeriods, type AgeColumn, type AgeRow } from "./ageing";
import { chooseColumns, withoutUnusedForeign } from "./columns";
import { GL_COLUMNS, GL_DEFAULTS, GL_FOREIGN, JOURNAL_COLUMNS, JOURNAL_DEFAULTS, JOURNAL_FOREIGN, glRow, journalEntries, type GlColumn } from "./ledgerColumns";
import type { Cell } from "./csv";

/*
 * Module 10: each report as rows for a CSV file, read from the same API the report page reads, under the same person's
 * session. Amounts stay decimal strings, as the API gives them.
 */

type Ctx = { token: string; companyId: string };
type Params = { from?: string; to?: string; as_of?: string; kind?: string; group?: string; account_id?: string; action?: string; user_id?: string; warehouse_id?: string;
  rows?: string; columns?: string; measure?: string; account_type?: string; project_id?: string; location_id?: string; class_id?: string;
  cols?: string; sort?: string; dir?: string; status?: string; view?: string; interval?: string; periods?: string; source?: string; by?: string; pct?: string; calendar?: Calendar; dateFormat?: string; compare?: string; basis?: string; open?: string; method?: string; weeks?: string; contact_id?: string };
/** A report's rows; a heading or period line when the report names them itself (a statement's customer, a forecast's weeks). */
export type Export = { title: string; rows: Cell[][]; heading?: string; period?: string };

export const EXPORTABLE = ["profit-and-loss", "balance-sheet", "trial-balance", "general-ledger", "cash-flow", "changes-in-equity",
  "profit-by-dimension", "sales", "purchases", "ar-ageing", "ap-ageing", "audit-log", "stock-movement", "stock-ageing", "custom",
  "transaction-list", "invoice-list", "open-invoices", "bill-list", "unpaid-bills", "payments-received", "payments-made", "journal",
  "cash-forecast", "inventory-valuation", "customer-statement", "supplier-statement"] as const;
export type ExportableReport = (typeof EXPORTABLE)[number];

type Ageing = Awaited<ReturnType<typeof ledgerly.arAgeing>>;

/** A date inside a line of text, written the company's way. */
const day = (p: Params, iso: string) => formatDate(iso, { calendar: p.calendar ?? "gregorian", dateFormat: pickDateFormat(p.dateFormat) });

/** The ageing summary: one line per party, a column per ageing period (as many as were asked for). */
const ageing = (title: string, r: Ageing): Export => {
  const buckets = r.buckets ?? DEFAULT_BUCKETS;
  return {
    title,
    rows: [["Name", ...buckets.map(bucketLabel), "Credits", "Total"],
      ...r.rows.map((x) => [x.name, ...buckets.map((b) => x[b] ?? null), x.credits, x.total]),
      ["Total", ...buckets.map((b) => r.totals[b] ?? null), r.totals.credits, r.totals.total]],
  };
};

/** The ageing detail as on screen: the columns chosen, in groups (ageing period or party), each with its subtotal. */
const ageingDetail = (title: string, r: Ageing, p: Params, credits: string): Export => {
  const list = ageRows(r);
  const q = { cols: p.cols };
  const cols = withoutUnusedForeign(chooseColumns(q, AGE_COLUMNS, AGE_DEFAULTS), list, q, AGE_FOREIGN);
  const value = (row: AgeRow, c: AgeColumn): Cell => (c === "age" ? bucketLabel(row.age) : c === "type" && row.type === "credit" ? credits : row[c] === "" ? null : row[c] ?? null);
  const rows: Cell[][] = [cols.map((c) => AGE_COLUMNS.find((d) => d.key === c)!.label)];
  const open = cols.indexOf("open_balance");
  const line = (label: string, amount: string | null) => cols.map((c, i) => (i === 0 ? label : i === open ? amount : null));
  for (const g of groupAgeRows(r, list, p.group === "name" ? "name" : "age", credits)) {
    rows.push(line(g.label, null));
    for (const row of g.rows) rows.push(row.type === "credit" ? line(row.name, row.open_balance) : cols.map((c) => value(row, c)));
    if (g.credits) rows.push(line(credits, g.credits));
    if (g.total !== null) rows.push(line(`Total ${g.label}`, g.total));
  }
  rows.push(line("Total", r.totals.total ?? null));
  return { title, rows };
};

/** The rows of a report for the period or date given (each report's own default otherwise). */
export async function exportRows(report: ExportableReport, p: Params, ctx: Ctx): Promise<Export> {
  switch (report) {
    case "profit-and-loss": {
      const by = pickDisplayBy(p.by);
      const pct = p.pct === "1";
      if (by !== "total") {
        // Display columns by, as on screen: a column per period or per customer or tag, a Total, and % of income.
        const split = columnsRequest(by, { from: p.from ?? "", to: p.to ?? "" }, p.calendar ?? "gregorian");
        if (split) {
          const c = await ledgerly.profitAndLossColumns({ from: p.from ?? "", to: p.to ?? "", by: split.by, ...(split.starts ? { starts: split.starts } : {}) }, ctx);
          const income = c.totals.income.total;
          const line = (l: { values: string[]; total: string }): Cell[] => [...l.values, l.total, ...(pct ? [percentOf(l.total, income)] : [])];
          const rows: Cell[][] = [["Section", "Code", "Account", ...c.columns.map((col, i) => split.labels?.[i] ?? col.label), "Total", ...(pct ? ["% of income"] : [])]];
          for (const [section, list, total] of [["Income", c.sections.income, c.totals.income], ["Cost of goods sold", c.sections.cost_of_sales, c.totals.cost_of_sales],
            ["Expenses", c.sections.expenses, c.totals.expenses]] as const) {
            for (const r of list) rows.push([section, r.code, r.name, ...line(r)]);
            rows.push([`Total ${section.toLowerCase()}`, null, null, ...line(total)]);
          }
          rows.push(["Gross profit", null, null, ...line(c.totals.gross_profit)], ["Net profit", null, null, ...line(c.totals.net_profit)]);
          return { title: "profit-and-loss", rows };
        }
      }
      // As on screen: against the period before or the same period last year (a closed period only), on the
      // accrual or cash basis.
      const before = p.from && p.to && p.open !== "1" ? (p.compare === "year" ? samePeriodLastYear(p.from, p.to) : previousPeriod(p.from, p.to)) : null;
      const pl = await ledgerly.profitAndLoss({ from: p.from ?? "", to: p.to ?? "", ...(before ? { compare_from: before.from, compare_to: before.to } : {}),
        ...(p.basis === "cash" ? { basis: "cash" as const } : {}) }, ctx);
      const share = (v: string): Cell[] => (pct ? [percentOf(v, pl.totals.income.current)] : []);
      const amounts = (v: { current: string; previous: string | null }): Cell[] => [v.current, ...(before ? [v.previous] : []), ...share(v.current)];
      const rows: Cell[][] = [["Section", "Code", "Account", "This period", ...(before ? [periodLabel(before.from, before.to)] : []), ...(pct ? ["% of income"] : [])]];
      for (const [section, list] of [["Income", pl.sections.income], ["Cost of goods sold", pl.sections.cost_of_sales], ["Expenses", pl.sections.expenses]] as const) {
        for (const r of list) rows.push([section, r.code, r.name, ...amounts(r)]);
      }
      rows.push(["Gross profit", null, null, ...amounts(pl.totals.gross_profit)], ["Net profit", null, null, ...amounts(pl.totals.net_profit)]);
      return { title: "profit-and-loss", rows };
    }
    case "balance-sheet": {
      // Against the same date a month, quarter or year earlier when the page compared, every account in either; % change.
      const asOf = p.as_of ?? p.to ?? "";
      const back = ({ month: 1, quarter: 3, year: 12 } as Record<string, number>)[p.compare ?? ""];
      const then = back ? shiftDay(asOf, -back, p.calendar ?? "gregorian") : null;
      const pct = Boolean(then) && p.pct === "1";
      const [bs, prior] = await Promise.all([ledgerly.balanceSheet(asOf, ctx), then ? ledgerly.balanceSheet(then, ctx) : Promise.resolve(null)]);
      const change = (a: string | null, b: string | null): Cell[] => (pct ? [percentChange(a ?? "0.00", b)] : []);
      const rows: Cell[][] = [["Section", "Code", "Account", asOf, ...(prior && then ? [then] : []), ...(pct ? ["% change"] : [])]];
      for (const [section, key] of [["Assets", "assets"], ["Liabilities", "liabilities"], ["Equity", "equity"]] as const) {
        const now = new Map(bs.sections[key].map((r) => [r.code, r]));
        const earlier = new Map((prior?.sections[key] ?? []).map((r) => [r.code, r]));
        for (const code of [...new Set([...now.keys(), ...earlier.keys()])].sort()) {
          rows.push([section, code, (now.get(code) ?? earlier.get(code))!.name, now.get(code)?.amount ?? null, ...(prior ? [earlier.get(code)?.amount ?? null] : []),
            ...change(now.get(code)?.amount ?? null, earlier.get(code)?.amount ?? null)]);
        }
      }
      rows.push(["Equity", null, "Profit not yet closed to retained earnings", bs.equation.profit_to_date, ...(prior ? [prior.equation.profit_to_date] : []),
        ...change(bs.equation.profit_to_date, prior?.equation.profit_to_date ?? null)],
        ["Check", null, "Difference", bs.equation.difference, ...(prior ? [prior.equation.difference] : []), ...(pct ? [null] : [])]);
      return { title: "balance-sheet", rows };
    }
    case "trial-balance": {
      const tb = await ledgerly.trialBalance(p.as_of ?? p.to ?? "", ctx);
      return { title: "trial-balance", rows: [["Code", "Account", "Type", "Debit", "Credit"], ...tb.data.map((r) => [r.code, r.name, r.type, r.debit, r.credit])] };
    }
    case "general-ledger": {
      // The same lines as on screen: the location, class and project filters too.
      // And the columns chosen on screen (Customize), after the account each line is on.
      const gl = await ledgerly.generalLedger({ from: p.from, to: p.to, account_id: p.account_id, location_id: p.location_id, class_id: p.class_id, project_id: p.project_id }, ctx);
      const q = { cols: p.cols };
      const cols = withoutUnusedForeign(chooseColumns(q, GL_COLUMNS, GL_DEFAULTS), gl.accounts.flatMap((a) => a.lines).map((l) => ({ currency: l.currency ?? null })), q, GL_FOREIGN);
      const value = (v: string | null, c: GlColumn): Cell => (v === "" ? null : c === "type" && v ? typeLabel(v) : v);
      const rows: Cell[][] = [["Account code", "Account", ...cols.map((c) => GL_COLUMNS.find((d) => d.key === c)!.label)]];
      for (const a of gl.accounts) {
        rows.push([a.code, a.name, ...cols.map((c, i) => (c === "balance" ? a.opening : i === 0 ? `Brought forward at ${day(p, gl.from)}` : null))]);
        for (const l of a.lines) {
          const r = glRow(l);
          rows.push([a.code, a.name, ...cols.map((c) => value(r[c], c))]);
        }
        rows.push([a.code, a.name, ...cols.map((c, i) => (c === "balance" ? a.closing : c === "debit" ? a.debits : c === "credit" ? a.credits : i === 0 ? `Balance at ${day(p, gl.to)}` : null))]);
      }
      return { title: "general-ledger", rows };
    }
    case "journal": {
      // Every entry with its lines, in the columns chosen on screen, each entry followed by its totals.
      const j = await ledgerly.journalReport({ from: p.from, to: p.to, ...(p.source ? { source: p.source } : {}) }, ctx);
      const q = { cols: p.cols };
      const cols = withoutUnusedForeign(chooseColumns(q, JOURNAL_COLUMNS, JOURNAL_DEFAULTS), j.lines.map((l) => ({ currency: l.currency ?? null })), q, JOURNAL_FOREIGN);
      const rows: Cell[][] = [cols.map((c) => JOURNAL_COLUMNS.find((d) => d.key === c)!.label)];
      for (const e of journalEntries(j.lines)) {
        for (const r of e.rows) rows.push(cols.map((c) => (r[c] === "" ? null : c === "type" && r[c] ? typeLabel(r[c]!) : r[c])));
        rows.push(cols.map((c) => (c === "debit" ? e.debits : c === "credit" ? e.credits : null)));
      }
      return { title: "journal", rows };
    }
    case "cash-flow": {
      if (p.method === "direct") {
        // The direct method: what the cash accounts received and paid, by what it was for, and each line's accounts.
        const d = await ledgerly.directCashFlow({ from: p.from, to: p.to }, ctx);
        const rows: Cell[][] = [["Line", "Code", "Account", "Amount"]];
        for (const l of d.lines) {
          rows.push([l.label, null, null, l.amount]);
          for (const a of l.accounts) rows.push([null, a.code, a.name, a.amount]);
        }
        rows.push(["Cash from operating activities", null, null, d.totals.operating], ["Cash from investing activities", null, null, d.totals.investing],
          ["Cash from financing activities", null, null, d.totals.financing], ["Net change", null, null, d.net_change],
          ["Cash at the start", null, null, d.opening_cash], ["Cash at the end", null, null, d.closing_cash]);
        return { title: "cash-flow-direct", rows };
      }
      const cf = await ledgerly.cashFlow({ from: p.from, to: p.to }, ctx);
      const rows: Cell[][] = [["Section", "Code", "Line", "Amount"], ["Operating", null, "Net profit for the period", cf.net_profit]];
      for (const [section, key] of [["Operating", "operating"], ["Investing", "investing"], ["Financing", "financing"]] as const) {
        for (const l of cf.sections[key]) rows.push([section, l.code, l.name, l.amount]);
        rows.push([section, null, `Cash from ${section.toLowerCase()} activities`, cf.totals[key]]);
      }
      rows.push(["Cash", null, "Net change", cf.net_change], ["Cash", null, "At the start", cf.opening_cash], ["Cash", null, "At the end", cf.closing_cash]);
      return { title: "cash-flow", rows };
    }
    case "changes-in-equity": {
      const s = await ledgerly.changesInEquity({ from: p.from, to: p.to }, ctx);
      return { title: "changes-in-equity", rows: [["Code", "Equity", "At the start", "Movement", "At the end"], ...s.lines.map((l) => [l.code, l.name, l.opening, l.movement, l.closing]),
        [null, "Total equity", s.opening, s.movement, s.closing]] };
    }
    case "profit-by-dimension": {
      const r = await ledgerly.profitByDimension({ kind: p.kind ?? "location", from: p.from, to: p.to }, ctx);
      return { title: `profit-by-${r.kind}`, rows: [["Code", r.kind, "Income", "Cost of sales", "Expenses", "Profit", "Margin %"],
        ...r.rows.map((x) => [x.code, x.name, x.income, x.cost_of_sales, x.expenses, x.profit, x.margin]),
        [null, "Total", r.totals.income, r.totals.cost_of_sales, r.totals.expenses, r.totals.profit, null]] };
    }
    case "transaction-list": case "invoice-list": case "open-invoices": case "bill-list": case "unpaid-bills": case "payments-received": case "payments-made": {
      // The columns, order and sort chosen on screen (Customize), and a total of the amount columns.
      const def = TX_REPORTS[report];
      const data = await ledgerly.transactionReport({ report: def.api, from: p.from, to: p.to, ...(p.status ? { status: p.status } : {}) }, ctx);
      const cols = pickColumns({ cols: p.cols }, def.columns);
      const order = pickSort({ sort: p.sort, dir: p.dir }, cols);
      const list = sortRows(data.rows, order.sort, order.dir);
      const sum = totals(list, cols);
      const value = (r: TxRow, c: TxColumn): Cell => (c === "type" ? typeLabel(r.type) : c === "status" ? statusLabel(r.status) : r[c] ?? null);
      return { title: report, rows: [cols.map((c) => column(c).label), ...list.map((r) => cols.map((c) => value(r, c))),
        ...(list.length > 0 ? [cols.map((c, i) => (i === 0 ? "Total" : sum[c] ?? null))] : [])] };
    }
    case "custom": {
      const r = await ledgerly.customReport({ from: p.from, to: p.to, rows: p.rows, columns: p.columns, measure: p.measure, account_type: p.account_type,
        project_id: p.project_id, location_id: p.location_id, class_id: p.class_id }, ctx);
      const many = r.columns.length > 1;
      return { title: `custom-by-${p.rows ?? "account"}`, rows: [["Code", "Name", ...(many ? r.columns : []), "Total"],
        ...r.rows.map((x) => [x.code, x.label, ...(many ? x.values : []), x.total]), [null, "Total", ...(many ? r.totals : []), r.total]] };
    }
    case "sales":
    case "purchases": {
      const r = await ledgerly.documentAnalysis(report, { group: p.group ?? "contact", from: p.from, to: p.to }, ctx);
      const heading = ({ contact: report === "sales" ? "Customer" : "Supplier", item: "Item", month: "Month", salesperson: "Salesperson" } as Record<string, string>)[r.group] ?? r.group;
      return { title: `${report}-by-${r.group}`, rows: [[heading, "Quantity", "Documents", "Amount", "Tax", "Share %"],
        ...r.rows.map((x) => [x.label, x.quantity, x.documents, x.amount, x.tax, x.share]), ["Total", null, null, r.totals.amount, r.totals.tax, null]] };
    }
    case "audit-log": {
      // Every row of the period (up to 50,000), page by page as the API gives them.
      const rows: Cell[][] = [["Id", "When", "Who", "Action", "Record", "Record id", "Before", "After", "Reason", "IP"]];
      let before: string | undefined;
      for (let page = 0; page < 500; page++) {
        const log = await ledgerly.auditLog({ from: p.from, to: p.to, action: p.action, user_id: p.user_id, before_id: before }, ctx);
        for (const e of log.data) rows.push([e.id, e.at, e.user?.name ?? e.actor_type, e.action, e.subject_type, e.subject_id,
          e.before ? JSON.stringify(e.before) : null, e.after ? JSON.stringify(e.after) : null, e.reason, e.ip]);
        if (!log.more || log.next_before_id === null) break;
        before = String(log.next_before_id);
      }
      return { title: "audit-log", rows };
    }
    case "stock-movement": {
      const r = await ledgerly.stockMovement({ from: p.from, to: p.to, warehouse_id: p.warehouse_id }, ctx);
      return { title: "stock-movement", rows: [["SKU", "Item", "Unit", "Opening quantity", "Opening value", "In quantity", "In value", "Out quantity", "Out value",
        "Sold quantity", "Closing quantity", "Closing value"],
        ...r.rows.map((x) => [x.item.sku, x.item.name, x.item.unit, x.opening_qty, x.opening_value, x.in_qty, x.in_value, x.out_qty, x.out_value, x.sold_qty,
          x.closing_qty, x.closing_value]),
        ["Total", null, null, null, r.totals.opening_value, null, r.totals.in_value, null, r.totals.out_value, null, null, r.totals.closing_value]] };
    }
    case "cash-forecast": {
      // Week by week from today: what comes in and goes out, and the cash at the end of each week.
      const weeks = [4, 8, 13, 26].includes(Number(p.weeks)) ? Number(p.weeks) : 13;
      const f = await ledgerly.cashForecast(weeks, ctx);
      const rows: Cell[][] = [["Week", "From", "To", "Customers owe", "Due to suppliers", "Coming in", "Going out", "Net", "Cash at the end"]];
      for (const w of f.data) rows.push([w.week, w.from, w.to, w.receivables, w.payables, w.in, w.out, w.net, w.closing]);
      rows.push(["Overdue", null, null, f.overdue.receivables, f.overdue.payables, null, null, null, null], ["Cash now", null, null, null, null, null, null, null, f.opening],
        ["Cash at the end", null, null, null, null, null, null, null, f.closing]);
      return { title: "cash-forecast", rows, period: `The next ${weeks} weeks, from ${day(p, f.as_of)}` };
    }
    case "inventory-valuation": {
      const v = await ledgerly.inventoryValuation(p.as_of, ctx);
      return { title: "inventory-valuation", rows: [["SKU", "Item", "Unit", "Warehouse", "Quantity", "Average cost", "Value"],
        ...v.rows.map((r) => [r.sku, r.name, r.unit, r.warehouse, r.quantity, perUnit(r.value, r.quantity), r.value]),
        ["Total", null, null, null, null, null, v.stock_value], ["Inventory account", null, null, null, null, null, v.inventory_account],
        ["Difference", null, null, null, null, null, v.difference]] };
    }
    case "customer-statement": case "supplier-statement": {
      // A customer's or supplier's statement for the period, in its standard columns, named in the heading.
      const payable = report === "supplier-statement";
      if (!p.contact_id) throw new Error("A statement needs its customer or supplier.");
      const s = payable ? await ledgerly.supplierStatement(p.contact_id, { from: p.from, to: p.to }, ctx) : await ledgerly.customerStatement(p.contact_id, { from: p.from, to: p.to }, ctx);
      const who = "supplier" in s ? s.supplier : s.customer;
      const defs = statementColumns(payable);
      const cols = STATEMENT_DEFAULTS.filter((c) => !["foreign_amount", "currency"].includes(c) || s.lines.some((l) => l.currency));
      const rows: Cell[][] = [cols.map((c) => defs.find((d) => d.key === c)!.label)];
      rows.push(cols.map((c, i) => (c === "balance" ? s.opening : i === 0 ? `Balance brought forward at ${day(p, s.from)}` : null)));
      s.lines.forEach((l, i) => { const r = statementRow(l, i, payable); rows.push(cols.map((c) => (c === "type" ? typeLabel(r.type) : r[c] === "" ? null : r[c]))); });
      rows.push(cols.map((c, i) => (c === "balance" ? s.closing : i === 0 ? `${payable ? "We owe" : "Owes"} at ${day(p, s.to)}` : null)));
      return { title: report, rows, heading: `${payable ? "Supplier" : "Customer"} statement: ${who.name}` };
    }
    case "stock-ageing": {
      const r = await ledgerly.stockAgeing({ as_of: p.as_of, warehouse_id: p.warehouse_id }, ctx);
      return { title: "stock-ageing", rows: [["SKU", "Item", "Unit", "Quantity", "Value", "Last sold", "Last received", "Days since sale", "Band"],
        ...r.rows.map((x) => [x.item.sku, x.item.name, x.item.unit, x.quantity, x.value, x.last_sold_on, x.last_received_on, x.days_since_sale, x.band]),
        ["Total", null, null, null, r.total_value, null, null, null, null]] };
    }
    case "ar-ageing": case "ap-ageing": {
      // The periods asked for (days per period, how many); the detail when the page showed it.
      const periods = pickPeriods({ interval: p.interval, periods: p.periods });
      const r = report === "ar-ageing" ? await ledgerly.arAgeing(p.as_of, ctx, periods) : await ledgerly.apAgeing(p.as_of, ctx, periods);
      if (p.view === "detail") return ageingDetail(report, r, p, report === "ap-ageing" ? "Advances and credits not used" : "Credit not used");
      return ageing(report, r);
    }
  }
}

const GROUP_WORDS: Record<string, string> = { contact: "", item: "item", month: "month", salesperson: "salesperson", location: "location", class: "class", project: "project" };

/** What a report is called in its header and file name ("Sales by customer", "Profit by location"). */
export function reportTitle(report: ExportableReport, o: { group?: string; kind?: string; view?: string; by?: string; method?: string } = {}): string {
  switch (report) {
    case "profit-and-loss": {
      const by = pickDisplayBy(o.by);
      return by === "total" ? "Profit and loss" : `Profit and loss by ${DISPLAY_BY.find(([k]) => k === by)![1].toLowerCase()}`;
    }
    case "balance-sheet": return "Balance sheet";
    case "trial-balance": return "Trial balance";
    case "general-ledger": return "General ledger";
    case "journal": return "Journal";
    case "cash-flow": return o.method === "direct" ? "Cash flow statement (direct method)" : "Cash flow statement";
    case "changes-in-equity": return "Statement of changes in equity";
    case "profit-by-dimension": return `Profit by ${GROUP_WORDS[o.kind ?? "location"] || "location"}`;
    case "sales": return `Sales by ${o.group && o.group !== "contact" ? GROUP_WORDS[o.group] ?? o.group : "customer"}`;
    case "purchases": return `Purchases by ${o.group && o.group !== "contact" ? GROUP_WORDS[o.group] ?? o.group : "supplier"}`;
    case "ar-ageing": return `Accounts receivable ageing ${o.view === "detail" ? "detail" : "summary"}`;
    case "ap-ageing": return `Accounts payable ageing ${o.view === "detail" ? "detail" : "summary"}`;
    case "audit-log": return "Audit log";
    case "stock-movement": return "Stock movement";
    case "stock-ageing": return "Stock ageing";
    case "cash-forecast": return "Cash forecast";
    case "inventory-valuation": return "Inventory valuation";
    case "customer-statement": return "Customer statement";
    case "supplier-statement": return "Supplier statement";
    case "custom": return "Custom report";
    case "transaction-list": case "invoice-list": case "open-invoices": case "bill-list": case "unpaid-bills": case "payments-received": case "payments-made":
      return TX_REPORTS[report].title;
  }
}

/** Reports kept on the books' basis (accrual unless the company or the report says cash): their footer says which. */
export const ON_A_BASIS: readonly ExportableReport[] = ["profit-and-loss", "balance-sheet", "trial-balance", "general-ledger", "changes-in-equity", "profit-by-dimension", "custom"];

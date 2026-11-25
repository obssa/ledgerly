import "server-only";
import { TX_REPORTS, column, pickColumns, pickSort, sortRows, statusLabel, totals, typeLabel, type TxColumn, type TxRow } from "./transactionColumns";
import { ledgerly } from "./api";
import type { Cell } from "./csv";

/*
 * Module 10: each report as rows for a CSV file, read from the same API the report page reads, under the same person's
 * session. Amounts stay decimal strings, as the API gives them.
 */

type Ctx = { token: string; companyId: string };
type Params = { from?: string; to?: string; as_of?: string; kind?: string; group?: string; account_id?: string; action?: string; user_id?: string; warehouse_id?: string;
  rows?: string; columns?: string; measure?: string; account_type?: string; project_id?: string; location_id?: string; class_id?: string;
  cols?: string; sort?: string; dir?: string; status?: string };
export type Export = { title: string; rows: Cell[][] };

export const EXPORTABLE = ["profit-and-loss", "balance-sheet", "trial-balance", "general-ledger", "cash-flow", "changes-in-equity",
  "profit-by-dimension", "sales", "purchases", "ar-ageing", "ap-ageing", "audit-log", "stock-movement", "stock-ageing", "custom",
  "transaction-list", "invoice-list", "open-invoices", "bill-list", "unpaid-bills", "payments-received", "payments-made"] as const;
export type ExportableReport = (typeof EXPORTABLE)[number];

const ageing = (title: string, r: Awaited<ReturnType<typeof ledgerly.arAgeing>>): Export => ({
  title,
  rows: [["Name", "Current", "1-30 days", "31-60 days", "61-90 days", "Over 90 days", "Credits", "Total"],
    ...r.rows.map((x) => [x.name, x.current, x.days_1_30, x.days_31_60, x.days_61_90, x.over_90, x.credits, x.total]),
    ["Total", r.totals.current, r.totals.days_1_30, r.totals.days_31_60, r.totals.days_61_90, r.totals.over_90, r.totals.credits, r.totals.total]],
});

/** The rows of a report for the period or date given (each report's own default otherwise). */
export async function exportRows(report: ExportableReport, p: Params, ctx: Ctx): Promise<Export> {
  switch (report) {
    case "profit-and-loss": {
      const pl = await ledgerly.profitAndLoss({ from: p.from ?? "", to: p.to ?? "" }, ctx);
      const rows: Cell[][] = [["Section", "Code", "Account", "Amount"]];
      for (const [section, list] of [["Income", pl.sections.income], ["Cost of goods sold", pl.sections.cost_of_sales], ["Expenses", pl.sections.expenses]] as const) {
        for (const r of list) rows.push([section, r.code, r.name, r.current]);
      }
      rows.push(["Gross profit", null, null, pl.totals.gross_profit.current], ["Net profit", null, null, pl.totals.net_profit.current]);
      return { title: "profit-and-loss", rows };
    }
    case "balance-sheet": {
      const bs = await ledgerly.balanceSheet(p.as_of ?? p.to ?? "", ctx);
      const rows: Cell[][] = [["Section", "Code", "Account", "Amount"]];
      for (const [section, list] of [["Assets", bs.sections.assets], ["Liabilities", bs.sections.liabilities], ["Equity", bs.sections.equity]] as const) {
        for (const r of list) rows.push([section, r.code, r.name, r.amount]);
      }
      rows.push(["Equity", null, "Profit not yet closed to retained earnings", bs.equation.profit_to_date], ["Check", null, "Difference", bs.equation.difference]);
      return { title: "balance-sheet", rows };
    }
    case "trial-balance": {
      const tb = await ledgerly.trialBalance(p.as_of ?? p.to ?? "", ctx);
      return { title: "trial-balance", rows: [["Code", "Account", "Type", "Debit", "Credit"], ...tb.data.map((r) => [r.code, r.name, r.type, r.debit, r.credit])] };
    }
    case "general-ledger": {
      // The same lines as on screen: the location, class and project filters too.
      const gl = await ledgerly.generalLedger({ from: p.from, to: p.to, account_id: p.account_id, location_id: p.location_id, class_id: p.class_id, project_id: p.project_id }, ctx);
      const rows: Cell[][] = [["Account code", "Account", "Date", "Entry", "Description", "Contact", "Debit", "Credit", "Balance"]];
      for (const a of gl.accounts) {
        rows.push([a.code, a.name, gl.from, null, "Brought forward", null, null, null, a.opening]);
        for (const l of a.lines) rows.push([a.code, a.name, l.date, l.entry, l.description ?? l.memo ?? l.source_type, l.contact, l.debit, l.credit, l.balance]);
      }
      return { title: "general-ledger", rows };
    }
    case "cash-flow": {
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
    case "stock-ageing": {
      const r = await ledgerly.stockAgeing({ as_of: p.as_of, warehouse_id: p.warehouse_id }, ctx);
      return { title: "stock-ageing", rows: [["SKU", "Item", "Unit", "Quantity", "Value", "Last sold", "Last received", "Days since sale", "Band"],
        ...r.rows.map((x) => [x.item.sku, x.item.name, x.item.unit, x.quantity, x.value, x.last_sold_on, x.last_received_on, x.days_since_sale, x.band]),
        ["Total", null, null, null, r.total_value, null, null, null, null]] };
    }
    case "ar-ageing":
      return ageing("ar-ageing", await ledgerly.arAgeing(p.as_of, ctx));
    case "ap-ageing":
      return ageing("ap-ageing", await ledgerly.apAgeing(p.as_of, ctx));
  }
}

const GROUP_WORDS: Record<string, string> = { contact: "", item: "item", month: "month", salesperson: "salesperson", location: "location", class: "class", project: "project" };

/** What a report is called in its header and file name ("Sales by customer", "Profit by location"). */
export function reportTitle(report: ExportableReport, o: { group?: string; kind?: string } = {}): string {
  switch (report) {
    case "profit-and-loss": return "Profit and loss";
    case "balance-sheet": return "Balance sheet";
    case "trial-balance": return "Trial balance";
    case "general-ledger": return "General ledger";
    case "cash-flow": return "Cash flow statement";
    case "changes-in-equity": return "Statement of changes in equity";
    case "profit-by-dimension": return `Profit by ${GROUP_WORDS[o.kind ?? "location"] || "location"}`;
    case "sales": return `Sales by ${o.group && o.group !== "contact" ? GROUP_WORDS[o.group] ?? o.group : "customer"}`;
    case "purchases": return `Purchases by ${o.group && o.group !== "contact" ? GROUP_WORDS[o.group] ?? o.group : "supplier"}`;
    case "ar-ageing": return "Accounts receivable ageing summary";
    case "ap-ageing": return "Accounts payable ageing summary";
    case "audit-log": return "Audit log";
    case "stock-movement": return "Stock movement";
    case "stock-ageing": return "Stock ageing";
    case "custom": return "Custom report";
    case "transaction-list": case "invoice-list": case "open-invoices": case "bill-list": case "unpaid-bills": case "payments-received": case "payments-made":
      return TX_REPORTS[report].title;
  }
}

/** Reports kept on the books' basis (accrual unless the company or the report says cash): their footer says which. */
export const ON_A_BASIS: readonly ExportableReport[] = ["profit-and-loss", "balance-sheet", "trial-balance", "general-ledger", "changes-in-equity", "profit-by-dimension", "custom"];

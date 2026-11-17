import "server-only";
import { ledgerly } from "./api";
import type { Cell } from "./csv";

/*
 * Module 10: each report as rows for a CSV file, read from the same API the report page reads, under the same person's
 * session. Amounts stay decimal strings, as the API gives them.
 */

type Ctx = { token: string; companyId: string };
type Params = { from?: string; to?: string; as_of?: string; kind?: string; group?: string; account_id?: string };
export type Export = { title: string; rows: Cell[][] };

export const EXPORTABLE = ["profit-and-loss", "balance-sheet", "trial-balance", "general-ledger", "cash-flow", "changes-in-equity",
  "profit-by-dimension", "sales", "purchases", "ar-ageing", "ap-ageing"] as const;
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
      const gl = await ledgerly.generalLedger({ from: p.from, to: p.to, account_id: p.account_id }, ctx);
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
    case "sales":
    case "purchases": {
      const r = await ledgerly.documentAnalysis(report, { group: p.group ?? "contact", from: p.from, to: p.to }, ctx);
      return { title: `${report}-by-${r.group}`, rows: [[r.group, "Quantity", "Documents", "Amount", "Tax", "Share %"],
        ...r.rows.map((x) => [x.label, x.quantity, x.documents, x.amount, x.tax, x.share]), ["Total", null, null, r.totals.amount, r.totals.tax, null]] };
    }
    case "ar-ageing":
      return ageing("ar-ageing", await ledgerly.arAgeing(p.as_of, ctx));
    case "ap-ageing":
      return ageing("ap-ageing", await ledgerly.apAgeing(p.as_of, ctx));
  }
}

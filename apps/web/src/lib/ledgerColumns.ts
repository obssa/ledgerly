import type { ColumnDef } from "./columns";
import type { LedgerLine, PartyStatementLine } from "./api";

/**
 * The general ledger's and the customer and supplier statements' columns (Customize, Columns), with QuickBooks'
 * standard ones: General ledger shows Date, Transaction type, No., Name, Memo, Split, Debit, Credit, Balance; a
 * statement shows Date, Transaction type, No., Memo, Due date, the charges and payments, and the running balance.
 */

/** Where a transaction opens: its invoice, bill or payment, or else its journal entry. */
export function transactionHref(t: { type?: string | null; document_id?: string | null; payment_id?: string | null; entry_id?: string | null }): string | null {
  const doc: Record<string, string> = { invoice: "/invoices", credit_note: "/credit-notes", bill: "/bills", sales_receipt: "/sales-receipts" };
  if (t.type && doc[t.type] && t.document_id) return `${doc[t.type]}/${encodeURIComponent(t.document_id)}`;
  const pay: Record<string, string> = { payment_received: "/payments", payment_made: "/payments-made" };
  if (t.type && pay[t.type] && t.payment_id) return `${pay[t.type]}/${encodeURIComponent(t.payment_id)}`;
  return t.entry_id ? `/journal/${encodeURIComponent(t.entry_id)}` : null;
}

export type GlRow = {
  id: string; entry_id: string; document_id: string | null; payment_id: string | null; date: string; type: string; number: string | null; entry: string; name: string | null;
  memo: string | null; split: string | null; due_date: string | null; debit: string; credit: string; amount: string; balance: string; foreign_amount: string | null;
  currency: string | null; exchange_rate: string | null; location: string | null; class: string | null; project: string | null; created_by: string | null; posted_by: string | null;
};
export type GlColumn = Exclude<keyof GlRow, "id" | "entry_id" | "document_id" | "payment_id">;

export const GL_COLUMNS: ColumnDef<GlColumn>[] = [
  { key: "date", label: "Date", group: "Transaction", kind: "date" },
  { key: "type", label: "Transaction type", group: "Transaction", kind: "text" },
  { key: "number", label: "No.", group: "Transaction", kind: "text" },
  { key: "entry", label: "Journal no.", group: "Transaction", kind: "text" },
  { key: "memo", label: "Memo/Description", group: "Transaction", kind: "text" },
  { key: "split", label: "Split", group: "Transaction", kind: "text" },
  { key: "name", label: "Name", group: "Customer or supplier", kind: "text" },
  { key: "due_date", label: "Due date", group: "Dates", kind: "date" },
  { key: "debit", label: "Debit", group: "Amounts", kind: "money" },
  { key: "credit", label: "Credit", group: "Amounts", kind: "money" },
  { key: "amount", label: "Amount", group: "Amounts", kind: "money" },
  { key: "balance", label: "Balance", group: "Amounts", kind: "money" },
  { key: "foreign_amount", label: "Foreign amount", group: "Foreign currency", kind: "money" },
  { key: "currency", label: "Currency", group: "Foreign currency", kind: "text" },
  { key: "exchange_rate", label: "Exchange rate", group: "Foreign currency", kind: "rate" },
  { key: "location", label: "Location", group: "Tracking", kind: "text" },
  { key: "class", label: "Class", group: "Tracking", kind: "text" },
  { key: "project", label: "Project", group: "Tracking", kind: "text" },
  { key: "created_by", label: "Created by", group: "People", kind: "text" },
  { key: "posted_by", label: "Posted by", group: "People", kind: "text" },
];
export const GL_DEFAULTS: GlColumn[] = ["date", "type", "number", "name", "memo", "split", "debit", "credit", "balance", "foreign_amount", "currency"];
export const GL_FOREIGN: GlColumn[] = ["foreign_amount", "currency", "exchange_rate"];

export function glRow(l: LedgerLine): GlRow {
  return {
    id: l.line_id, entry_id: l.entry_id, document_id: l.document_id ?? null, payment_id: l.payment_id ?? null, date: l.date, type: l.type ?? l.source_type,
    number: l.number ?? null, entry: `#${l.entry}`, name: l.contact, memo: l.description ?? l.memo, split: l.split ?? null, due_date: l.due_date ?? null,
    debit: l.debit, credit: l.credit, amount: l.amount ?? "", balance: l.balance, foreign_amount: l.foreign_amount ?? null, currency: l.currency ?? null,
    exchange_rate: l.exchange_rate ?? null, location: l.location ?? null, class: l.class ?? null, project: l.project ?? null,
    created_by: l.created_by ?? null, posted_by: l.posted_by ?? null,
  };
}

export type StatementRow = {
  id: string; entry_id: string | null; document_id: string | null; payment_id: string | null; date: string; type: string; number: string | null; entry: string; memo: string | null;
  due_date: string | null; charge: string; payment: string; balance: string; open_balance: string | null; foreign_amount: string | null; currency: string | null;
  exchange_rate: string | null; created_by: string | null;
};
export type StatementColumn = Exclude<keyof StatementRow, "id" | "entry_id" | "document_id" | "payment_id">;

/** A customer's charges are debits on the receivable account; a supplier's bills are credits on accounts payable. */
export function statementColumns(payable: boolean): ColumnDef<StatementColumn>[] {
  return [
    { key: "date", label: "Date", group: "Transaction", kind: "date" },
    { key: "type", label: "Transaction type", group: "Transaction", kind: "text" },
    { key: "number", label: "No.", group: "Transaction", kind: "text" },
    { key: "entry", label: "Journal no.", group: "Transaction", kind: "text" },
    { key: "memo", label: "Memo", group: "Transaction", kind: "text" },
    { key: "due_date", label: "Due date", group: "Dates", kind: "date" },
    { key: "charge", label: payable ? "Billed" : "Charged", group: "Amounts", kind: "money" },
    { key: "payment", label: payable ? "Paid" : "Paid or credited", group: "Amounts", kind: "money" },
    { key: "balance", label: payable ? "We owe" : "Balance", group: "Amounts", kind: "money" },
    { key: "open_balance", label: "Open balance", group: "Amounts", kind: "money" },
    { key: "foreign_amount", label: "Foreign amount", group: "Foreign currency", kind: "money" },
    { key: "currency", label: "Currency", group: "Foreign currency", kind: "text" },
    { key: "exchange_rate", label: "Exchange rate", group: "Foreign currency", kind: "rate" },
    { key: "created_by", label: "Created by", group: "People", kind: "text" },
  ];
}
export const STATEMENT_DEFAULTS: StatementColumn[] = ["date", "type", "number", "memo", "due_date", "charge", "payment", "balance", "foreign_amount", "currency"];
export const STATEMENT_FOREIGN: StatementColumn[] = ["foreign_amount", "currency", "exchange_rate"];

export function statementRow(l: PartyStatementLine, i: number, payable: boolean): StatementRow {
  const type = l.type ?? (l.source_type === "payment" ? (payable ? "payment_made" : "payment_received") : l.source_type);
  // A foreign amount is signed on the ledger (+debit, -credit); on the statement it reads as the charge or payment.
  const foreign = l.foreign_amount ? (payable ? negate(l.foreign_amount) : l.foreign_amount) : null;
  return {
    id: `${l.entry}-${i}`, entry_id: l.entry_id ?? null, document_id: l.document_id, payment_id: l.payment_id ?? null, date: l.date, type, number: l.reference,
    entry: `#${l.entry}`, memo: l.memo, due_date: l.due_date ?? null, charge: payable ? l.credit : l.debit, payment: payable ? l.debit : l.credit, balance: l.balance,
    open_balance: l.open_balance ?? null, foreign_amount: foreign, currency: l.currency ?? null, exchange_rate: l.exchange_rate ?? null, created_by: l.created_by ?? null,
  };
}

const negate = (v: string) => (v.startsWith("-") ? v.slice(1) : v === "0.00" ? v : `-${v}`);

import type { ColumnDef } from "./columns";
import { parse, toString } from "./decimal";
import type { BinCard, JournalReport, LedgerLine, PartyStatementLine } from "./api";

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
  memo: string | null; split: string | null; due_date: string | null; debit: string; credit: string; amount: string; balance: string; foreign_amount: string | null; foreign_balance: string | null;
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
  { key: "foreign_balance", label: "Foreign balance", group: "Foreign currency", kind: "money" },
  { key: "currency", label: "Currency", group: "Foreign currency", kind: "text" },
  { key: "exchange_rate", label: "Exchange rate", group: "Foreign currency", kind: "rate" },
  { key: "location", label: "Location", group: "Tracking", kind: "text" },
  { key: "class", label: "Class", group: "Tracking", kind: "text" },
  { key: "project", label: "Project", group: "Tracking", kind: "text" },
  { key: "created_by", label: "Created by", group: "People", kind: "text" },
  { key: "posted_by", label: "Posted by", group: "People", kind: "text" },
];
export const GL_DEFAULTS: GlColumn[] = ["date", "type", "number", "name", "memo", "split", "debit", "credit", "balance", "foreign_amount", "foreign_balance", "currency"];
export const GL_FOREIGN: GlColumn[] = ["foreign_amount", "foreign_balance", "currency", "exchange_rate"];

export function glRow(l: LedgerLine): GlRow {
  return {
    id: l.line_id, entry_id: l.entry_id, document_id: l.document_id ?? null, payment_id: l.payment_id ?? null, date: l.date, type: l.type ?? l.source_type,
    number: l.number ?? null, entry: `#${l.entry}`, name: l.contact, memo: l.description ?? l.memo, split: l.split ?? null, due_date: l.due_date ?? null,
    debit: l.debit, credit: l.credit, amount: l.amount ?? "", balance: l.balance, foreign_amount: l.foreign_amount ?? null, foreign_balance: l.foreign_balance ?? null, currency: l.currency ?? null,
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

/** The Journal report: every entry with its lines; QuickBooks shows Date, Transaction type, No., Name, Memo, Account, Debit, Credit. */
export type JournalRow = GlRow & { account: string };
export type JournalColumn = Exclude<GlColumn, "balance" | "split"> | "account";
export const JOURNAL_COLUMNS: ColumnDef<JournalColumn>[] = [
  ...GL_COLUMNS.filter((c): c is ColumnDef<Exclude<GlColumn, "balance" | "split">> => c.key !== "balance" && c.key !== "split").slice(0, 6),
  { key: "account", label: "Account", group: "Transaction", kind: "text" },
  ...GL_COLUMNS.filter((c): c is ColumnDef<Exclude<GlColumn, "balance" | "split">> => c.key !== "balance" && c.key !== "split").slice(6),
];
export const JOURNAL_DEFAULTS: JournalColumn[] = ["date", "type", "number", "name", "memo", "account", "debit", "credit", "foreign_amount", "currency"];
export const JOURNAL_FOREIGN: JournalColumn[] = ["foreign_amount", "currency", "exchange_rate"];

export function journalRow(l: JournalReport["lines"][number]): JournalRow {
  return { ...glRow(l), account: `${l.account_code} ${l.account_name}` };
}

/** The entries of the journal, each with its lines in order and its totals (debits equal credits). */
export function journalEntries(lines: JournalReport["lines"]): { entryId: string; rows: JournalRow[]; debits: string; credits: string }[] {
  const out = new Map<string, JournalRow[]>();
  for (const l of lines) out.set(l.entry_id, [...(out.get(l.entry_id) ?? []), journalRow(l)]);
  const sum = (rows: JournalRow[], k: "debit" | "credit") => toString(rows.reduce((t, r) => t + parse(r[k]), 0n));
  return [...out.entries()].map(([entryId, rows]) => ({ entryId, rows, debits: sum(rows, "debit"), credits: sum(rows, "credit") }));
}

/** The bin card: each stock move with its document, customer or supplier, and who made it. */
export type BinRow = {
  id: string; document_id: string | null; date: string; kind: string; reference: string | null; document: string | null; name: string | null;
  qty_in: string; qty_out: string; unit_cost: string; value: string; balance_qty: string; balance_value: string; created_by: string | null;
};
export type BinColumn = Exclude<keyof BinRow, "id" | "document_id">;
export const BIN_COLUMNS: ColumnDef<BinColumn>[] = [
  { key: "date", label: "Date", group: "Move", kind: "date" },
  { key: "kind", label: "What", group: "Move", kind: "text" },
  { key: "reference", label: "Reference", group: "Move", kind: "text" },
  { key: "document", label: "Document", group: "Move", kind: "text" },
  { key: "name", label: "Customer or supplier", group: "Move", kind: "text" },
  { key: "qty_in", label: "In", group: "Quantity", kind: "count" },
  { key: "qty_out", label: "Out", group: "Quantity", kind: "count" },
  { key: "balance_qty", label: "Balance", group: "Quantity", kind: "count" },
  { key: "unit_cost", label: "Unit cost", group: "Value", kind: "money" },
  { key: "value", label: "Value moved", group: "Value", kind: "money" },
  { key: "balance_value", label: "Value", group: "Value", kind: "money" },
  { key: "created_by", label: "Created by", group: "People", kind: "text" },
];
export const BIN_DEFAULTS: BinColumn[] = ["date", "kind", "reference", "name", "qty_in", "qty_out", "unit_cost", "balance_qty", "balance_value"];

const DOC_WORD: Record<string, string> = { invoice: "Invoice", credit_note: "Credit note", bill: "Bill", vendor_credit: "Supplier credit", sales_receipt: "Cash sale",
  delivery_note: "Delivery note", goods_receipt: "Goods received" };

export function binRow(m: BinCard["moves"][number], i: number): BinRow {
  const doc = m.document_number ? `${DOC_WORD[m.document_type ?? ""] ?? ""} ${m.document_number}`.trim() : null;
  return { id: `${m.moved_on}-${i}`, document_id: m.document_id ?? null, date: m.moved_on, kind: m.kind, reference: m.reference, document: doc, name: m.name ?? null,
    qty_in: m.qty_in, qty_out: m.qty_out, unit_cost: m.unit_cost, value: m.value, balance_qty: m.balance_qty, balance_value: m.balance_value, created_by: m.created_by ?? null };
}

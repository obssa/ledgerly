import type { ImportKind } from "./imports";

/**
 * Module 1 (096): moving from another program. For each, the reports to export and the order to bring them in: the
 * chart first (customers and suppliers need nothing from it, but open invoices and opening balances name its
 * accounts), then customers, suppliers and items, then what was open and the balances on the day you start.
 */
export type MigrationSource = "quickbooks" | "sage" | "tally";

export const MIGRATION_SOURCES: Record<MigrationSource, { name: string; note: string; steps: { kind: ImportKind; how: string }[] }> = {
  quickbooks: {
    name: "QuickBooks",
    note: "In QuickBooks Online switch account numbers on first (Settings, Account and settings, Advanced, Chart of accounts). Bank accounts and the receivable and payable are left out of the chart: open banks under Banking.",
    steps: [
      { kind: "accounts", how: "Reports, Account List: export to Excel. Account #, Account and Type are read; the types become the kinds here." },
      { kind: "customers", how: "Sales, Customers: export. Customer, Email, Phone, the billing address and Terms (Net 30) are read." },
      { kind: "suppliers", how: "Expenses, Vendors: export. Vendor, Email, Phone, Tax ID and the address are read." },
      { kind: "items", how: "Sales, Products and services: export. Product/Service Name, SKU, Type, Sales Price / Rate and Purchase Cost are read." },
      { kind: "open-invoices", how: "Reports, Open Invoices on the day before you start: export, then keep Customer, Num, Date, Due Date and Open Balance." },
      { kind: "open-bills", how: "Reports, Unpaid Bills on the same day: keep Vendor, Num, Date, Due Date and Open Balance." },
      { kind: "opening-balances", how: "Reports, Trial Balance on the same day, with account numbers: the Account # column and Debit and Credit." },
    ],
  },
  sage: {
    name: "Sage",
    note: "Sage 50 and Sage Pastel export reports as CSV. The bank and the debtors and creditors control accounts are left out of the chart: open banks under Banking.",
    steps: [
      { kind: "accounts", how: "Nominal codes (chart of accounts) list: Nominal Code, Name and Category; Sales, Purchases, Overheads, Fixed Assets and the rest become the kinds here." },
      { kind: "customers", how: "Customer list: Name, Telephone, E-mail, VAT Reg No, Street1, Town and Post Code are read." },
      { kind: "suppliers", how: "Supplier list: the same headings." },
      { kind: "items", how: "Product list: Description in a Name column, Product Code (as SKU), Sales Price and Cost Price." },
      { kind: "open-invoices", how: "Aged debtors detail on the day before you start: customer, invoice number, date and the outstanding amount." },
      { kind: "open-bills", how: "Aged creditors detail on the same day." },
      { kind: "opening-balances", how: "Trial balance on the same day: Nominal Code, Debit and Credit." },
    ],
  },
  tally: {
    name: "Tally",
    note: "Tally has no account codes: add a Code column before you import the ledgers. Its groups become the types here; Sundry Debtors and Creditors are customers and suppliers, Bank Accounts and Cash-in-Hand are opened under Banking.",
    steps: [
      { kind: "accounts", how: "Display, List of Accounts (ledgers), exported to Excel: Name and Under (the group), with a Code column added." },
      { kind: "customers", how: "The ledgers under Sundry Debtors: Name, Mailing address, State, PIN Code and phone." },
      { kind: "suppliers", how: "The ledgers under Sundry Creditors: the same columns." },
      { kind: "items", how: "Stock items: Name, the stock item's alias as SKU, and its rates." },
      { kind: "open-invoices", how: "Bills receivable on the day before you start: party, bill reference, date and pending amount." },
      { kind: "open-bills", how: "Bills payable on the same day." },
      { kind: "opening-balances", how: "Trial balance on the same day, with the codes you added: Code, Debit and Credit." },
    ],
  },
};

export function migrationSource(v: unknown): MigrationSource | null {
  return typeof v === "string" && v in MIGRATION_SOURCES ? (v as MigrationSource) : null;
}

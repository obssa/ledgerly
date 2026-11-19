/** Module 1: what can be brought in from a spreadsheet saved as CSV, and the columns each reads. */
export const IMPORT_KINDS = {
  customers: {
    label: "Customers",
    columns: ["Name", "TIN", "Email", "Phone", "Website", "Currency", "Terms (days)", "Credit limit", "Address", "City", "Region", "Notes"],
    example: "Name,TIN,Email,Phone,Terms,Credit limit,City\nKaldi Cafe,0012345678,orders@kaldi.example,+251 911 000 000,30,50000,Addis Ababa",
  },
  suppliers: {
    label: "Suppliers",
    columns: ["Name", "TIN", "Email", "Phone", "Website", "Currency", "Terms (days)", "Address", "City", "Region", "Notes"],
    example: "Name,TIN,Email,Phone,Terms\nAddis Packaging,0098765432,sales@packaging.example,+251 911 111 111,30",
  },
  items: {
    label: "Items",
    columns: ["Name", "Code (SKU)", "Type (inventory, non inventory or service)", "Unit", "Price", "Cost", "Reorder point"],
    example: "Name,Code,Type,Unit,Price,Cost\nGreen beans 60 kg,GB-60,inventory,bag,12500,9800\nRoasting service,RS-1,service,hour,800,",
  },
  accounts: {
    label: "Chart of accounts",
    columns: ["Code", "Name", "Type (asset, liability, equity, income or expense)", "Kind (optional, e.g. operating, cogs, loan)", "Parent (its code)"],
    example: "Code,Name,Type,Kind,Parent\n6200,Marketing,expense,operating,\n6210,Social media ads,expense,operating,6200",
    required: "Code, Name and Type are required. A parent is listed above its accounts; codes already in the chart are skipped.",
  },
  "opening-balances": {
    label: "Opening balances",
    columns: ["Code (of the account)", "Debit", "Credit", "or Balance (debit positive, credit negative)", "Name (only to read the file)"],
    example: "Code,Name,Debit,Credit\n1110,Main bank account,250000,\n2210,VAT payable,,18000",
    required: "Your trial balance at the conversion date, one line per account. It replaces the opening balances entered before; any difference goes to opening balance equity. Receivables, payables and stock come from their open invoices, bills and stock counts.",
  },
  "open-invoices": {
    label: "Open invoices",
    columns: ["Customer (as named in Ledgerly)", "Invoice number (from the old books)", "Date", "Due date", "Amount (still open)", "Currency", "Rate"],
    example: "Customer,Invoice number,Date,Due date,Amount\nKaldi Cafe,OLD-0042,15/06/2026,15/07/2026,11500",
    required: "Customer, Invoice number, Date and Amount are required: what each customer still owed on the conversion date. Dates as 2026-06-15 or 15/06/2026.",
  },
  "open-bills": {
    label: "Open bills",
    columns: ["Supplier (as named in Ledgerly)", "Supplier invoice number", "Date", "Due date", "Amount (still open)", "Currency", "Rate", "Number (ours, if numbered by hand)"],
    example: "Supplier,Supplier invoice number,Date,Amount\nAddis Packaging,AP-55,20/06/2026,4000",
    required: "Supplier, Supplier invoice number, Date and Amount are required: what was still owed to each supplier on the conversion date.",
  },
  journals: {
    label: "Journals",
    columns: ["Date", "Reference (rows with the same date and reference are one entry)", "Memo", "Account (its code)", "Debit", "Credit", "Description"],
    example: "Date,Reference,Memo,Account,Debit,Credit\n2026-11-30,ACC-11,Audit fee accrued,6800,15000,\n2026-11-30,ACC-11,,2100,,15000",
    required: "Date, Account, and Debit or Credit are required; each entry balances. They post as manual journals (above the approval limit they wait for approval), all or none. Receivables, payables and stock are not journalled: they come from their documents.",
  },
} as const;

export type ImportKind = keyof typeof IMPORT_KINDS;

/** What a kind needs, in a sentence. */
export function importRequires(kind: ImportKind): string {
  const k = IMPORT_KINDS[kind];
  return "required" in k ? k.required : "Only Name is required.";
}

export function importKind(v: unknown): ImportKind {
  return typeof v === "string" && v in IMPORT_KINDS ? (v as ImportKind) : "customers";
}

export interface ImportResult {
  kind: ImportKind;
  checked: boolean;
  rows: number;
  added: number;
  errors: { row: number; name: string | null; messages: string[] }[];
  columns: string[];
  ignored_columns: string[];
  /** Account codes already in the chart, left as they are. */
  skipped?: string[];
}

/** What the result says, in a sentence. */
export function importSummary(r: ImportResult): string {
  const what = r.kind === "accounts" ? "accounts" : IMPORT_KINDS[r.kind].label.toLowerCase();
  if (r.errors.length > 0) {
    return `${r.errors.length} of ${r.rows} rows need a fix; nothing was ${r.checked ? "kept (this was a check)" : "added"}. Correct the file and try again.`;
  }
  if (r.kind === "open-invoices" || r.kind === "open-bills") {
    const noun = r.kind === "open-bills" ? "bills" : "invoices";
    return r.checked ? `All ${r.rows} rows are fine. Import them to bring in the open ${noun}.` : `${r.added} open ${noun} brought in.`;
  }
  if (r.kind === "journals") {
    return r.checked ? `All ${r.rows} lines are fine. Import them to post the journals.` : `${r.added} journals posted (or sent for approval).`;
  }
  if (r.kind === "opening-balances") {
    return r.checked ? `All ${r.rows} lines are fine. Import them to enter the opening balances.` : `Opening balances entered: ${r.added} accounts.`;
  }
  const skipped = r.skipped && r.skipped.length > 0 ? ` ${r.skipped.length} already in the chart, left as they are (${r.skipped.join(", ")}).` : "";
  return (r.checked ? `All ${r.rows} rows are fine. Import them to add the ${what}.` : `${r.added} ${what} added.`) + skipped;
}

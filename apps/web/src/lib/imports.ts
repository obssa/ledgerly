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
} as const;

export type ImportKind = keyof typeof IMPORT_KINDS;

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
}

/** What the result says, in a sentence. */
export function importSummary(r: ImportResult): string {
  const what = IMPORT_KINDS[r.kind].label.toLowerCase();
  if (r.errors.length > 0) {
    return `${r.errors.length} of ${r.rows} rows need a fix; nothing was ${r.checked ? "kept (this was a check)" : "added"}. Correct the file and try again.`;
  }
  return r.checked ? `All ${r.rows} rows are fine. Import them to add the ${what}.` : `${r.added} ${what} added.`;
}

/**
 * Turns the invoice form into the body of POST /invoices. The lines travel as one JSON field (the form edits them in
 * the browser); anything that is not a list of lines becomes an empty list, so the API names the problem. Amounts
 * stay decimal strings, thousands separators removed. Empty optional fields are left out.
 */

export interface InvoiceLineInput {
  description: string;
  quantity: string;
  unit_price: string;
  account_id: string;
  tax_rate_id: string | null;
  item_id?: string | null;
  project_id?: string | null;
  /** The sales order line it delivers (067), kept through edits. */
  source_line_id?: string | null;
  /** Another unit of the item (072): quantity and price are in it. */
  unit_id?: string | null;
  /** The batch a bill line brings in (074), by number and expiry. */
  batch_number?: string | null;
  batch_expires_on?: string | null;
  /** 111: QuickBooks' Service date, and the line's class and location when tracked per line. */
  service_date?: string | null;
  class_id?: string | null;
  location_id?: string | null;
}

const clean = (v: unknown): string => (typeof v === "string" ? v.replace(/[,\s]/g, "") : "");
const str = (v: unknown): string => (typeof v === "string" ? v.trim() : "");

export function parseLines(json: FormDataEntryValue | null): InvoiceLineInput[] {
  if (typeof json !== "string") return [];
  let raw: unknown;
  try {
    raw = JSON.parse(json);
  } catch {
    return [];
  }
  if (!Array.isArray(raw)) return [];
  return raw.slice(0, 500).map((l: Record<string, unknown>) => ({
    description: str(l?.description),
    quantity: clean(l?.quantity),
    unit_price: clean(l?.unit_price),
    account_id: str(l?.account_id),
    tax_rate_id: str(l?.tax_rate_id) || null,
    ...(str(l?.item_id) ? { item_id: str(l?.item_id) } : {}),
    ...(str(l?.project_id) ? { project_id: str(l?.project_id) } : {}),
    ...(str(l?.source_line_id) ? { source_line_id: str(l?.source_line_id) } : {}),
    ...(str(l?.unit_id) ? { unit_id: str(l?.unit_id) } : {}),
    ...(/^\d{4}-\d{2}-\d{2}$/.test(str(l?.service_date)) ? { service_date: str(l?.service_date) } : {}),
    ...(str(l?.class_id) ? { class_id: str(l?.class_id) } : {}),
    ...(str(l?.location_id) ? { location_id: str(l?.location_id) } : {}),
    ...(str(l?.batch_number) ? { batch_number: str(l?.batch_number), ...(str(l?.batch_expires_on) ? { batch_expires_on: str(l?.batch_expires_on) } : {}) } : {}),
  }));
}

export function invoiceBody(form: FormData): Record<string, unknown> {
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };
  const body: Record<string, unknown> = {
    contact_id: value("contact_id"),
    issue_date: value("issue_date"),
    currency: value("currency"),
    // 110: Amounts are; the old checkbox still means inclusive.
    prices_include_tax: form.get("tax_mode") === "inclusive" || form.get("prices_include_tax") === "on",
    lines: parseLines(form.get("lines")),
  };
  for (const name of ["due_date", "notes", "number", "reference", "paid_from_account_id", "payment_method", "warehouse_id", "salesperson_id", "payment_terms_id"]) if (value(name) !== "") body[name] = value(name);
  if (["exclusive", "inclusive", "out_of_scope"].includes(value("tax_mode"))) body.tax_mode = value("tax_mode");
  // 111: the class and location of the whole document (when tracked so), and the company's custom fields.
  for (const name of ["class_id", "location_id"]) if (value(name) !== "") body[name] = value(name);
  // 112: shown on the form, they are sent even when emptied (an empty one clears it).
  for (const name of ["billing_address", "shipping_address", "email_to", "email_cc", "email_bcc", "ship_via", "ship_date", "tracking_number", "message_on_statement"]) {
    if (form.has(name)) body[name] = value(name) === "" ? null : value(name);
  }
  const custom: Record<string, string> = {};
  for (const [key, v] of form.entries()) if (key.startsWith("custom_fields.") && typeof v === "string") custom[key.slice("custom_fields.".length)] = v.trim();
  if (Object.keys(custom).length > 0) body.custom_fields = custom;
  // The next number shown, kept as it was: the server takes the next free one when it saves (someone may have taken it meanwhile).
  if (value("number_suggested") !== "" && value("number") === value("number_suggested")) delete body.number;
  if (clean(form.get("fx_rate")) !== "") body.fx_rate = clean(form.get("fx_rate"));
  return body;
}

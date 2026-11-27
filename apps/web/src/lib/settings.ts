import type { FormField } from "./api";
/**
 * Turns the company settings form into the body of PUT /company. Only fields that are on the form are sent: a field
 * that is locked (disabled once anything is posted) is not submitted, so it is left as it is. An emptied optional
 * field is sent as null, so it is cleared.
 */

const REQUIRED = ["legal_name", "timezone", "accounting_basis", "base_currency", "calendar"] as const;
const OPTIONAL = [
  "trading_name", "legal_form", "industry", "tin", "vat_number", "phone", "email", "website",
  "address_line1", "address_line2", "city", "region", "postal_code", "conversion_date",
] as const;

/** The date formats a company can choose (Settings, Date format), as the API accepts them. */
export const DATE_FORMAT_CHOICES = ["DD/MM/YYYY", "MM/DD/YYYY", "YYYY-MM-DD"] as const;

/** Approval limits a company can set (base currency). */
export const APPROVAL_LIMITS = ["invoice_above", "bill_above", "journal_above"] as const;

/** A checkbox sends nothing when unticked, so the form carries this marker to say the checkbox was shown. */
export const VAT_SHOWN = "vat_registered_shown";
export const REMINDERS_SHOWN = "reminders_shown";
export const TAX_REMINDERS_SHOWN = "tax_reminders_shown";
export const INTEREST_SHOWN = "late_interest_shown";
/** Marks that the invoice template fields were on the form (so empty texts clear them). */
export const TEMPLATE_SHOWN = "invoice_template_shown";

/** "7, 30" → [7, 30]: whole days after the due date, at most three. Anything else is left for the API to refuse. */
export function reminderDays(text: string): number[] {
  return text.split(/[,;\s]+/).filter(Boolean).map(Number).slice(0, 3);
}

export function settingsBody(form: FormData): Record<string, unknown> {
  const body: Record<string, unknown> = { version: String(form.get("version") ?? "") };
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };

  for (const name of REQUIRED) if (form.has(name)) body[name] = value(name);
  for (const name of OPTIONAL) if (form.has(name)) body[name] = value(name) === "" ? null : value(name);
  if (form.has("fiscal_year_start_month")) body.fiscal_year_start_month = Number(value("fiscal_year_start_month"));
  if (form.has("date_format") && (DATE_FORMAT_CHOICES as readonly string[]).includes(value("date_format"))) body.date_format = value("date_format");
  if (form.has(VAT_SHOWN)) body.vat_registered = form.get("vat_registered") === "on";
  if (["exclusive", "inclusive", "out_of_scope"].includes(value("amounts_are"))) body.amounts_are = value("amounts_are");
  // Approval limits: above them someone other than the creator approves. Empty means no limit.
  const limits = APPROVAL_LIMITS.filter((k) => form.has(`approval.${k}`));
  if (limits.length > 0) {
    body.approval = Object.fromEntries(limits.map((k) => {
      const v = value(`approval.${k}`).replace(/[,\s]/g, "");
      return [k, v === "" ? null : v];
    }));
  }

  if (form.has(TAX_REMINDERS_SHOWN)) body.tax_reminders = form.get("tax_reminders") === "on";
  if (form.has(INTEREST_SHOWN)) {
    const rate = value("late_interest.rate").replace(/[%\s]/g, "");
    body.late_interest = rate === "" ? { rate: null, account_id: null } : { rate, account_id: value("late_interest.account_id") || null };
  }
  if (form.has(TEMPLATE_SHOWN)) {
    const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
    body.invoice_template = {
      accent: /^#[0-9A-Fa-f]{6}$/.test(value("invoice_template.accent")) ? value("invoice_template.accent") : null,
      payment_details: value("invoice_template.payment_details") || null,
      footer: value("invoice_template.footer") || null,
    };
  }
  if (form.has(REMINDERS_SHOWN)) body.reminders = { enabled: form.get("reminders.enabled") === "on", days: reminderDays(value("reminders.days")) };

  return body;
}

/** The form fields Customize turns on or off (111), in QuickBooks' words. */
export const FORM_FIELDS: { key: FormField; label: string; sales: boolean; purchases: boolean }[] = [
  { key: "service_date", label: "Service date on each line", sales: true, purchases: true },
  { key: "sku", label: "SKU on each line", sales: true, purchases: true },
  { key: "shipping", label: "Shipping (ship to, ship via, date, tracking no.)", sales: true, purchases: false },
  { key: "email_cc_bcc", label: "Cc and Bcc on emails", sales: true, purchases: false },
  { key: "message_on_statement", label: "Message on statement", sales: true, purchases: false },
  { key: "discount", label: "Discount", sales: true, purchases: false },
  { key: "deposit", label: "Deposit", sales: true, purchases: false },
  { key: "tags", label: "Tags", sales: true, purchases: true },
  { key: "attachments", label: "Attachments", sales: true, purchases: true },
];

/** Customize from the form: each field for sales and purchases, tracking, and up to three custom fields (empty names dropped). */
export function formContentBody(form: FormData): Record<string, unknown> {
  const on = (name: string) => form.get(name) === "on";
  const pick = (v: FormDataEntryValue | null, allowed: string[]) => (typeof v === "string" && allowed.includes(v) ? v : "off");
  const side = (prefix: "sales" | "purchases") =>
    Object.fromEntries(FORM_FIELDS.filter((f) => f[prefix]).map((f) => [f.key, on(`${prefix}.${f.key}`)]));
  const custom = [0, 1, 2].map((i) => ({
    name: String(form.get(`custom.${i}.name`) ?? "").trim(),
    sales: on(`custom.${i}.sales`), purchases: on(`custom.${i}.purchases`), print: on(`custom.${i}.print`),
  })).filter((f) => f.name !== "");
  return {
    sales: side("sales"), purchases: side("purchases"),
    track_classes: pick(form.get("track_classes"), ["off", "transaction", "line"]),
    track_locations: pick(form.get("track_locations"), ["off", "transaction", "line"]),
    custom_fields: custom,
  };
}

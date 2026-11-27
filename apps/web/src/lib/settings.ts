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

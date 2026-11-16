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

/** Approval limits a company can set (base currency). */
export const APPROVAL_LIMITS = ["invoice_above", "bill_above", "journal_above"] as const;

/** A checkbox sends nothing when unticked, so the form carries this marker to say the checkbox was shown. */
export const VAT_SHOWN = "vat_registered_shown";

export function settingsBody(form: FormData): Record<string, unknown> {
  const body: Record<string, unknown> = { version: String(form.get("version") ?? "") };
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };

  for (const name of REQUIRED) if (form.has(name)) body[name] = value(name);
  for (const name of OPTIONAL) if (form.has(name)) body[name] = value(name) === "" ? null : value(name);
  if (form.has("fiscal_year_start_month")) body.fiscal_year_start_month = Number(value("fiscal_year_start_month"));
  if (form.has(VAT_SHOWN)) body.vat_registered = form.get("vat_registered") === "on";
  // Approval limits: above them someone other than the creator approves. Empty means no limit.
  const limits = APPROVAL_LIMITS.filter((k) => form.has(`approval.${k}`));
  if (limits.length > 0) {
    body.approval = Object.fromEntries(limits.map((k) => {
      const v = value(`approval.${k}`).replace(/[,\s]/g, "");
      return [k, v === "" ? null : v];
    }));
  }

  return body;
}

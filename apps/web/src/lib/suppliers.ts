/**
 * Turns the supplier form into the body of POST/PUT /suppliers. Emptied optional fields are sent as null (cleared);
 * the address and the payout details are sent as objects without empty parts.
 */

const OPTIONAL = ["tax_id", "email", "phone", "website", "currency", "notes", "withholding_exempt_reason", "default_expense_account_id"] as const;
const ADDRESS = ["line1", "line2", "city", "region", "postal_code", "country"] as const;
export const PAYOUT = ["bank_name", "account_name", "account_number", "mobile_money_provider", "mobile_money_number"] as const;

/** A checkbox sends nothing when unticked, so the form carries this marker to say it was shown. */
export const WITHHOLDING_SHOWN = "withholding_shown";

export function supplierBody(form: FormData): Record<string, unknown> {
  const value = (name: string) => {
    const v = form.get(name);
    return typeof v === "string" ? v.trim() : "";
  };
  const body: Record<string, unknown> = {};

  if (form.has("name")) body.name = value("name");
  for (const name of OPTIONAL) if (form.has(name)) body[name] = value(name) === "" ? null : value(name);
  if (form.has("payment_terms_days")) body.payment_terms_days = Number(value("payment_terms_days") || 0);
  // 109: their usual term; "None" keeps the days they had.
  if (form.has("payment_terms_id")) body.payment_terms_id = value("payment_terms_id") === "" ? null : value("payment_terms_id");
  if (form.has(WITHHOLDING_SHOWN)) body.withholding_exempt = form.get("withholding_exempt") === "on";

  if (ADDRESS.some((part) => form.has(`address.${part}`))) {
    const address: Record<string, string> = {};
    for (const part of ADDRESS) {
      const v = value(`address.${part}`);
      if (v !== "") address[part] = part === "country" ? v.toUpperCase() : v;
    }
    body.address = address;
  }
  if (PAYOUT.some((part) => form.has(`payout.${part}`))) {
    const payout: Record<string, string> = {};
    for (const part of PAYOUT) {
      const v = value(`payout.${part}`);
      if (v !== "") payout[part] = v;
    }
    body.payout = payout;
  }

  return body;
}

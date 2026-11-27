/**
 * Turns the customer form into the body of POST/PUT /customers. Emptied optional fields are sent as null (cleared);
 * addresses are sent as objects without empty parts; amounts keep their decimal string, thousands separators removed.
 */

const OPTIONAL = ["tax_id", "email", "phone", "website", "currency", "notes", "tax_exempt_reason", "price_list_id"] as const;
const ADDRESS = ["line1", "line2", "city", "region", "postal_code", "country"] as const;

/** A checkbox sends nothing when unticked, so the form carries this marker to say it was shown. */
export const EXEMPT_SHOWN = "tax_exempt_shown";

export function customerBody(form: FormData): Record<string, unknown> {
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
  if (form.has("credit_limit")) body.credit_limit = value("credit_limit") === "" ? null : value("credit_limit").replace(/[,\s]/g, "");
  if (form.has(EXEMPT_SHOWN)) body.tax_exempt = form.get("tax_exempt") === "on";

  for (const prefix of ["address", "delivery_address"] as const) {
    if (!ADDRESS.some((part) => form.has(`${prefix}.${part}`))) continue;
    const address: Record<string, string> = {};
    for (const part of ADDRESS) {
      const v = value(`${prefix}.${part}`);
      if (v !== "") address[part] = part === "country" ? v.toUpperCase() : v;
    }
    body[prefix] = address;
  }

  return body;
}

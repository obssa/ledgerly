/* Module 9c: what the fixed asset form sends. The API checks everything again. */

const clean = (v: string) => v.replace(/[,\s]/g, "");

/** A rate typed as a percentage ("25" or "25%") is sent as a fraction (0.25); a fraction is sent as it is. */
export function rateFromPercent(v: string): string | null {
  const s = clean(v).replace("%", "");
  if (s === "") return null;
  const n = Number(s);
  if (!Number.isFinite(n)) return s;
  return n >= 1 ? String(Math.round(n * 10000) / 1000000) : s;
}

/** The body of POST or PUT /fixed-assets: amounts without separators, the method's own field only. */
export function assetBody(form: FormData): Record<string, unknown> {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  const method = value("method") === "reducing_balance" ? "reducing_balance" : "straight_line";
  return {
    code: value("code"), name: value("name"), category: value("category") || null, location_id: value("location_id") || null, custodian: value("custodian") || null,
    asset_account_id: value("asset_account_id"), depreciation_account_id: value("depreciation_account_id"), expense_account_id: value("expense_account_id"),
    acquired_on: value("acquired_on"), cost: clean(value("cost")), residual_value: clean(value("residual_value")) || null, method,
    useful_life_months: method === "straight_line" ? (value("useful_life_months") || null) : null,
    annual_rate: method === "reducing_balance" ? rateFromPercent(value("annual_rate")) : null,
    depreciation_starts_on: value("depreciation_starts_on") || null, opening_depreciation: clean(value("opening_depreciation")) || null,
  };
}

import { describe, expect, it } from "vitest";
import { settingsBody, VAT_SHOWN } from "./settings";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("settingsBody", () => {
  it("sends only the fields on the form, so locked (disabled) fields are left alone", () => {
    const body = settingsBody(form([["version", "v1"], ["legal_name", " Abyssinia Coffee "], ["city", "Addis Ababa"]]));
    expect(body).toEqual({ version: "v1", legal_name: "Abyssinia Coffee", city: "Addis Ababa" });
    expect(body).not.toHaveProperty("base_currency");
  });

  it("clears an emptied optional field", () => {
    expect(settingsBody(form([["version", "v1"], ["trading_name", "  "]]))).toEqual({ version: "v1", trading_name: null });
  });

  it("reads the fiscal year start as a number and the VAT box only when it was shown", () => {
    expect(settingsBody(form([["version", "v1"], ["fiscal_year_start_month", "7"], [VAT_SHOWN, "1"]]))).toEqual({
      version: "v1", fiscal_year_start_month: 7, vat_registered: false,
    });
    expect(settingsBody(form([["version", "v1"], [VAT_SHOWN, "1"], ["vat_registered", "on"]]))).toEqual({ version: "v1", vat_registered: true });
    expect(settingsBody(form([["version", "v1"]]))).not.toHaveProperty("vat_registered");
  });
});

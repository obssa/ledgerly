import { describe, expect, it } from "vitest";
import { assetBody, rateFromPercent } from "./assets";

describe("rateFromPercent", () => {
  it("reads a percentage or a fraction", () => {
    expect(rateFromPercent("25")).toBe("0.25");
    expect(rateFromPercent("25%")).toBe("0.25");
    expect(rateFromPercent("12.5")).toBe("0.125");
    expect(rateFromPercent("0.2")).toBe("0.2");
    expect(rateFromPercent("")).toBeNull();
  });
});

describe("assetBody", () => {
  it("sends only the chosen method's figure, amounts without separators", () => {
    const f = new FormData();
    for (const [k, v] of [["code", " VAN-1 "], ["name", "Van"], ["asset_account_id", "a"], ["depreciation_account_id", "d"], ["expense_account_id", "e"],
      ["acquired_on", "2026-11-03"], ["cost", "1,250,000.00"], ["method", "reducing_balance"], ["useful_life_months", "60"], ["annual_rate", "20"]]) f.append(k, v);
    expect(assetBody(f)).toMatchObject({ code: "VAN-1", cost: "1250000.00", method: "reducing_balance", useful_life_months: null, annual_rate: "0.2", residual_value: null, location_id: null });
    f.set("method", "straight_line");
    expect(assetBody(f)).toMatchObject({ useful_life_months: "60", annual_rate: null });
    expect(assetBody(f)).toMatchObject({ tax_method: null, tax_annual_rate: null });
    f.set("tax_method", "reducing_balance"); f.set("tax_annual_rate", "25"); f.set("tax_useful_life_months", "48");
    expect(assetBody(f)).toMatchObject({ tax_method: "reducing_balance", tax_annual_rate: "0.25", tax_useful_life_months: null });
  });
});

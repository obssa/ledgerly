import { describe, expect, it } from "vitest";
import { invoiceBody, parseLines } from "./invoice";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("invoiceBody", () => {
  it("sends the header, the lines from their JSON field, and leaves empty optional fields out", () => {
    const lines = JSON.stringify([{ description: " Coffee ", quantity: "240", unit_price: "1,150.00", account_id: "a1", tax_rate_id: "vat15" }]);
    expect(invoiceBody(form([
      ["contact_id", "c1"], ["issue_date", "2026-11-02"], ["due_date", ""], ["currency", "ETB"], ["lines", lines], ["notes", ""],
    ]))).toEqual({
      contact_id: "c1", issue_date: "2026-11-02", currency: "ETB", prices_include_tax: false,
      lines: [{ description: "Coffee", quantity: "240", unit_price: "1150.00", account_id: "a1", tax_rate_id: "vat15" }],
    });
  });

  it("keeps the rate and the typed number when given, and VAT-inclusive prices", () => {
    expect(invoiceBody(form([["fx_rate", "134.5233"], ["number", "A-000123"], ["prices_include_tax", "on"], ["lines", "[]"]]))).toMatchObject({
      fx_rate: "134.5233", number: "A-000123", prices_include_tax: true, lines: [],
    });
  });
});

describe("parseLines", () => {
  it("turns anything that is not a list of lines into no lines, so the API names the problem", () => {
    expect(parseLines("not json")).toEqual([]);
    expect(parseLines('{"a":1}')).toEqual([]);
    expect(parseLines(null)).toEqual([]);
    expect(parseLines('[{"description":"x","tax_rate_id":""}]')).toEqual([{ description: "x", quantity: "", unit_price: "", account_id: "", tax_rate_id: null }]);
  });
});

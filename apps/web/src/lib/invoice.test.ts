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

describe("stock lines", () => {
  it("keeps a line's item and the document's warehouse", () => {
    const f = new FormData();
    f.append("contact_id", "c1");
    f.append("warehouse_id", "w1");
    f.append("salesperson_id", "u1");
    f.append("lines", JSON.stringify([{ description: "Coffee", quantity: "2", unit_price: "1,150", account_id: "a1", tax_rate_id: null, item_id: "i1" }, { description: "Fee", quantity: "1", unit_price: "10", account_id: "a1", item_id: "" }]));
    const body = invoiceBody(f);
    expect(body.warehouse_id).toBe("w1");
    expect(body.salesperson_id).toBe("u1");
    expect(body.lines).toEqual([
      { description: "Coffee", quantity: "2", unit_price: "1150", account_id: "a1", tax_rate_id: null, item_id: "i1" },
      { description: "Fee", quantity: "1", unit_price: "10", account_id: "a1", tax_rate_id: null },
    ]);
  });
});

describe("lines made from a sales order", () => {
  it("keep the order line they deliver, and a line without one sends none", () => {
    const lines = parseLines(JSON.stringify([
      { description: "Bags", quantity: "3", unit_price: "100", account_id: "a", tax_rate_id: null, source_line_id: "0a1b2c3d-0000-4000-8000-000000000001" },
      { description: "Extra", quantity: "1", unit_price: "5", account_id: "a", tax_rate_id: null, source_line_id: "" },
    ]));
    expect(lines[0].source_line_id).toBe("0a1b2c3d-0000-4000-8000-000000000001");
    expect("source_line_id" in lines[1]).toBe(false);
  });

  it("keeps a line's unit (072), and leaves it out when the line is in the item's own unit", () => {
    const lines = parseLines(JSON.stringify([
      { description: "Cartons", quantity: "2", unit_price: "600", account_id: "a", tax_rate_id: null, unit_id: " 0a1b2c3d-0000-4000-8000-000000000009 " },
      { description: "Bags", quantity: "3", unit_price: "50", account_id: "a", tax_rate_id: null, unit_id: "" },
    ]));
    expect(lines[0].unit_id).toBe("0a1b2c3d-0000-4000-8000-000000000009");
    expect("unit_id" in lines[1]).toBe(false);
  });
});

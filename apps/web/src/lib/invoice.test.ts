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

  it("leaves the next number shown unsent when it is kept, so the server takes the next free one; a typed one is sent", () => {
    const kept = invoiceBody(form([["number", "INV-2027-0022"], ["number_suggested", "INV-2027-0022"], ["lines", "[]"]]));
    expect(kept).not.toHaveProperty("number");
    expect(kept).not.toHaveProperty("number_suggested");
    expect(invoiceBody(form([["number", "202601-22"], ["number_suggested", "INV-2027-0022"], ["lines", "[]"]]))).toMatchObject({ number: "202601-22" });
  });

  it("sends Amounts are, and inclusive as prices that include tax", () => {
    expect(invoiceBody(form([["tax_mode", "out_of_scope"], ["lines", "[]"]]))).toMatchObject({ tax_mode: "out_of_scope", prices_include_tax: false });
    expect(invoiceBody(form([["tax_mode", "inclusive"], ["lines", "[]"]]))).toMatchObject({ tax_mode: "inclusive", prices_include_tax: true });
    expect(invoiceBody(form([["tax_mode", "anything"], ["lines", "[]"]]))).not.toHaveProperty("tax_mode");
  });

  it("sends the class, location and custom fields of the document, and each line's service date, class and location", () => {
    const lines = JSON.stringify([{ description: "Roasting", quantity: "1", unit_price: "10", account_id: "a1", service_date: "2026-10-31", class_id: "c1", location_id: "", tax_rate_id: "" }]);
    expect(invoiceBody(form([["class_id", "c9"], ["location_id", ""], ["custom_fields.PO number", " HGK-77 "], ["lines", lines]]))).toMatchObject({
      class_id: "c9", custom_fields: { "PO number": "HGK-77" },
      lines: [{ description: "Roasting", quantity: "1", unit_price: "10", account_id: "a1", tax_rate_id: null, service_date: "2026-10-31", class_id: "c1" }],
    });
    expect(invoiceBody(form([["lines", "[]"]]))).not.toHaveProperty("custom_fields");
  });

  it("sends the addresses, email and shipping shown on the form, an emptied one as null, and leaves out those not shown", () => {
    const body = invoiceBody(form([["billing_address", " Bole Road\nAddis Ababa "], ["email_to", "ap@hotel.example"], ["email_cc", ""], ["ship_via", "Ethiopian Cargo"], ["lines", "[]"]]));
    expect(body).toMatchObject({ billing_address: "Bole Road\nAddis Ababa", email_to: "ap@hotel.example", email_cc: null, ship_via: "Ethiopian Cargo" });
    expect(body).not.toHaveProperty("shipping_address");
  });

  it("sends the term with the due date it set, and no term when none is chosen", () => {
    expect(invoiceBody(form([["payment_terms_id", "t30"], ["due_date", "2026-12-02"], ["lines", "[]"]]))).toMatchObject({ payment_terms_id: "t30", due_date: "2026-12-02" });
    expect(invoiceBody(form([["payment_terms_id", ""], ["lines", "[]"]]))).not.toHaveProperty("payment_terms_id");
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

  it("keeps a bill line's batch and expiry (074), the expiry only with a batch", () => {
    const lines = parseLines(JSON.stringify([
      { description: "Milk", quantity: "6", unit_price: "200", account_id: "a", tax_rate_id: null, batch_number: " L-2601 ", batch_expires_on: "2027-01-31" },
      { description: "Milk", quantity: "1", unit_price: "200", account_id: "a", tax_rate_id: null, batch_number: "", batch_expires_on: "2027-01-31" },
    ]));
    expect(lines[0]).toMatchObject({ batch_number: "L-2601", batch_expires_on: "2027-01-31" });
    expect("batch_number" in lines[1] || "batch_expires_on" in lines[1]).toBe(false);
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

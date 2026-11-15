import { describe, expect, it } from "vitest";
import { customerBody, EXEMPT_SHOWN } from "./customers";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("customerBody", () => {
  it("sends the fields on the form, clears emptied ones and keeps amounts as decimal strings", () => {
    expect(customerBody(form([
      ["name", " Sheger Grand Hotel "], ["tax_id", ""], ["email", "ap@sheger.example"], ["payment_terms_days", "15"],
      ["credit_limit", "250,000.00"],
    ]))).toEqual({ name: "Sheger Grand Hotel", tax_id: null, email: "ap@sheger.example", payment_terms_days: 15, credit_limit: "250000.00" });
  });

  it("builds addresses without empty parts and with an upper-case country", () => {
    expect(customerBody(form([["address.line1", "Bole Road"], ["address.city", "Addis Ababa"], ["address.line2", ""], ["address.country", "et"]]))).toEqual({
      address: { line1: "Bole Road", city: "Addis Ababa", country: "ET" },
    });
  });

  it("reads the exemption box only when it was shown, and an empty credit limit as none", () => {
    expect(customerBody(form([[EXEMPT_SHOWN, "1"], ["credit_limit", ""]]))).toEqual({ tax_exempt: false, credit_limit: null });
    expect(customerBody(form([[EXEMPT_SHOWN, "1"], ["tax_exempt", "on"], ["tax_exempt_reason", "Embassy"]]))).toEqual({ tax_exempt: true, tax_exempt_reason: "Embassy" });
  });
});

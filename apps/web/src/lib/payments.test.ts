import { describe, expect, it } from "vitest";
import { allocateOldestFirst, paymentBody, paymentTotals } from "./payments";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("paymentBody", () => {
  it("sends the header and only the invoices with an amount", () => {
    expect(paymentBody(form([
      ["contact_id", "c1"], ["account_id", "bank"], ["payment_date", "2026-11-10"], ["method", "cheque"], ["currency", "ETB"],
      ["amount", "1,115.50"], ["withholding", "34.50"], ["fee", "0"], ["reference", ""],
      ["alloc:i1", "1,150.00"], ["alloc:i2", ""], ["alloc:i3", "0.00"],
    ]))).toEqual({
      contact_id: "c1", account_id: "bank", payment_date: "2026-11-10", method: "cheque", currency: "ETB",
      amount: "1115.50", withholding: "34.50", allocations: [{ invoice_id: "i1", amount: "1150.00" }],
    });
  });
});

describe("allocateOldestFirst", () => {
  it("settles the oldest invoices first and stops when the money runs out", () => {
    expect(allocateOldestFirst("1500", ["1000.00", "300.00", "800.00"])).toEqual(["1000.00", "300.00", "200.00"]);
    expect(allocateOldestFirst("50", ["100.00", "100.00"])).toEqual(["50.00", ""]);
    expect(allocateOldestFirst("", ["100.00"])).toEqual([""]);
  });
});

describe("paymentTotals", () => {
  it("counts the tax withheld as part of what the payment settles, and shows the credit left over", () => {
    expect(paymentTotals("970", "30", ["900.00", "50"])).toEqual({ settles: "1000.00", allocated: "950.00", credit: "50.00" });
    expect(paymentTotals("abc", "", ["1"])).toEqual({ settles: "0.00", allocated: "1.00", credit: "-1.00" });
  });
});

import { describe, expect, it } from "vitest";
import { allocateOldestFirst, paymentBody, paymentTotals, settleProblem as problemOf, suggestedWithholding, withholdingBasis } from "./payments";

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

describe("withholding on a payment made", () => {
  it("is worked out on the pre-VAT share of what is paid on each bill", () => {
    // 23,000.00 bill of which 20,000.00 before VAT; paying all of it, and half of a 1,150.00 bill (1,000.00 before VAT).
    expect(withholdingBasis(["23000", "575"], [{ subtotal: "20000.00", total: "23000.00" }, { subtotal: "1000.00", total: "1150.00" }])).toBe("20500.00");
    expect(withholdingBasis(["", "0"], [{ subtotal: "100.00", total: "115.00" }, { subtotal: "1.00", total: "0.00" }])).toBe("0.00");
  });

  it("is suggested only above the threshold, at the rate, half-cents up", () => {
    expect(suggestedWithholding("0.030000", "20500.00", "10000.00")).toBe("615.00");
    expect(suggestedWithholding("0.03", "10000.00", "10000.00")).toBe("");
    expect(suggestedWithholding("0.03", "10000.50", "10000.00")).toBe("300.02");
    expect(suggestedWithholding(null, "50000.00", "10000.00")).toBe("");
  });
});

describe("paymentBody for a payment made", () => {
  it("names bills and keeps the withholding certificate", () => {
    const f = new FormData();
    for (const [k, v] of [["contact_id", "s1"], ["amount", "22,400.00"], ["withholding", "600"], ["withholding_certificate", " WHT-12 "], ["alloc:b1", "23,000"], ["alloc:b2", ""]]) f.append(k, v);
    expect(paymentBody(f, "bill")).toMatchObject({ amount: "22400.00", withholding: "600", withholding_certificate: "WHT-12", allocations: [{ bill_id: "b1", amount: "23000" }] });
  });
});

describe("cheque numbers", () => {
  it("are sent for a cheque and left out for any other method", () => {
    const base: [string, string][] = [["contact_id", "s1"], ["account_id", "bank"], ["payment_date", "2026-11-10"], ["currency", "ETB"], ["amount", "50"], ["cheque_number", "000101"]];
    expect(paymentBody(form([...base, ["method", "cheque"]]), "bill")).toMatchObject({ method: "cheque", cheque_number: "000101" });
    expect(paymentBody(form([...base, ["method", "bank_transfer"]]), "bill")).not.toHaveProperty("cheque_number");
  });
});


describe("a payment says what it settles", () => {
  it("refuses an empty Settle column while invoices are open", () => {
    expect(problemOf(["", ""], 2, false, "invoice")).toMatch(/Fill in the Settle column/);
    expect(problemOf(["0", "0.00"], 2, false, "bill")).toMatch(/Fill in the Pay column/);
  });
  it("accepts a settled amount, a deliberate credit, or nothing open", () => {
    expect(problemOf(["", "1,150.00"], 2, false, "invoice")).toBeNull();
    expect(problemOf(["", ""], 2, true, "invoice")).toBeNull();
    expect(problemOf([], 0, false, "invoice")).toBeNull();
  });
});

describe("a payment through an account in another currency (QuickBooks' way)", () => {
  it("offers accounts in the payment's currency first, then the company's, never a third", async () => {
    const { paymentAccounts } = await import("./payments");
    const accounts = [{ id: "birr", currency: null }, { id: "eur", currency: "EUR" }, { id: "usd", currency: "USD" }];
    expect(paymentAccounts(accounts, "USD", "ETB").map((a) => a.id)).toEqual(["usd", "birr"]);
    expect(paymentAccounts(accounts, "ETB", "ETB").map((a) => a.id)).toEqual(["birr"]);
  });

  it("works out the bank's rate from what the account moved", async () => {
    const { bankRate } = await import("./payments");
    expect(bankRate("14000", "100")).toBe("140.000000");
    expect(bankRate("31,000.00".replace(/,/g, ""), "200")).toBe("155.000000");
    expect(bankRate("100", "3")).toBe("33.333333");
    expect(bankRate("", "100")).toBeNull();
    expect(bankRate("100", "0")).toBeNull();
  });

  it("lets a contact be paid in their own currency, their open documents' or the company's", async () => {
    const { paymentCurrencies } = await import("./payments");
    expect(paymentCurrencies(null, [{ currency: "USD" }, { currency: "USD" }], "ETB", ["USD"])).toEqual(["USD", "ETB"]);
    expect(paymentCurrencies("USD", [], "ETB", ["USD"])).toEqual(["USD", "ETB"]);
    expect(paymentCurrencies(null, [], "ETB", [])).toEqual(["ETB"]);
    expect(paymentCurrencies("GBP", [], "ETB", ["USD"])).toEqual(["ETB"]);   // a currency turned off is not offered
  });
});

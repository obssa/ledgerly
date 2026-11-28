import { describe, expect, it } from "vitest";
import { CURRENCIES, discountedAmounts, documentAmounts, lineAmounts, parse, toString } from "./decimal";
import { formatAmount, formatLongDate, toEthiopian } from "./format";

// Same cases as apps/api/tests/Unit/TaxCalculatorTest.php: browser preview and API must agree.
describe("tax maths (mirrors TaxCalculator)", () => {
  it("matches the design mockup invoice", () => {
    const doc = documentAmounts(
      [
        { quantity: "240", unitPrice: "1150", taxRate: "0.15" },
        { quantity: "160", unitPrice: "980", taxRate: "0.15" },
        { quantity: "1", unitPrice: "3500", taxRate: "0.15" },
      ],
      "ETB",
    );
    expect(toString(doc.subtotal)).toBe("436300.00");
    expect(toString(doc.tax)).toBe("65445.00");
    expect(toString(doc.total)).toBe("501745.00");
  });

  it("applies discount before tax", () => {
    const l = lineAmounts({ quantity: "3", unitPrice: "100", discountRate: "0.10", taxRate: "0.16" }, "KES");
    expect([toString(l.subtotal), toString(l.tax), toString(l.total)]).toEqual(["270.00", "43.20", "313.20"]);
  });

  it("splits tax-inclusive prices without losing a cent", () => {
    const l = lineAmounts({ quantity: "1", unitPrice: "99.99", taxRate: "0.15" }, "ETB", true);
    expect(toString(l.subtotal + l.tax)).toBe("99.99");
    expect(toString(l.subtotal)).toBe("86.95");
  });

  it("keeps two decimals for every currency", () => {
    const l = lineAmounts({ quantity: "3", unitPrice: "1999", taxRate: "0.18" }, "UGX");
    expect(toString(l.tax)).toBe("1079.46");
  });

  it("keeps fractional rates exact (7.5%)", () => {
    const l = lineAmounts({ quantity: "1", unitPrice: "199.99", taxRate: "0.075" }, "NGN");
    expect([toString(l.tax), toString(l.total)]).toEqual(["15.00", "214.99"]);
  });

  it("never uses floats: 0.1 + 0.2 is exactly 0.3", () => {
    expect(toString(parse("0.1") + parse("0.2"))).toBe("0.30");
  });

  it("rounds half away from zero", () => {
    expect(toString(parse("10.005"))).toBe("10.01");
    expect(toString(parse("-10.005"))).toBe("-10.01");
  });
});

describe("formatting", () => {
  it("groups thousands without float conversion", () => {
    expect(formatAmount("501745.00", "ETB")).toBe("501,745.00");
    expect(formatAmount("1234567.5", "UGX")).toBe("1,234,567.50");
  });

  it("converts to the Ethiopian calendar", () => {
    expect(toEthiopian(new Date("2026-11-02T00:00:00Z")).label).toBe("Tikimt 23, 2019");
    const newYear = toEthiopian(new Date("2026-09-11T00:00:00Z"));
    expect([newYear.year, newYear.month, newYear.day]).toEqual([2019, 1, 1]);
    const pagume = toEthiopian(new Date("2023-09-11T00:00:00Z"));
    expect([pagume.year, pagume.month, pagume.day]).toEqual([2015, 13, 6]);
  });

  it("writes a day in the company's calendar only, never both", () => {
    const day = new Date("2026-11-25T00:00:00Z");
    expect(formatLongDate(day, "gregorian")).toBe("Wednesday, 25 November 2026");
    expect(formatLongDate(day, "ethiopian")).toBe("Wednesday, Hidar 16, 2019");
    expect(formatLongDate(day, "gregorian")).not.toContain("Hidar");
    expect(formatLongDate(day, "ethiopian")).not.toContain("November");
  });

  it("offers every currency, with no duplicates", () => {
    expect(CURRENCIES.length).toBeGreaterThan(140);
    expect(new Set(CURRENCIES).size).toBe(CURRENCIES.length);
    for (const c of ["ETB", "USD", "EUR", "BRL", "INR", "JPY"]) expect(CURRENCIES).toContain(c);
  });
});

describe("discountedAmounts", () => {
  // The same cases as apps/api/tests/Unit/TaxCalculatorTest.php: the web app and the server agree.
  const l = (amount: string, taxRate = "0.15") => ({ quantity: "1", unitPrice: amount, taxRate });
  const s = (r: ReturnType<typeof discountedAmounts>) => [toString(r.discount), toString(r.taxable), toString(r.tax), toString(r.total)];
  it("shares a document discount over the lines before tax", () => {
    expect(s(discountedAmounts([l("600"), l("400")], "ETB", false, { type: "percent", value: "10" }))).toEqual(["100.00", "900.00", "135.00", "1035.00"]);
    expect(s(discountedAmounts([l("100"), l("100"), l("100")], "ETB", false, { type: "amount", value: "100" }))).toEqual(["100.00", "200.00", "30.00", "230.00"]);
    expect(toString(discountedAmounts([l("300")], "ETB", false, { type: "amount", value: "500" }).discount)).toBe("300.00");
    expect(s(discountedAmounts([l("500", "0"), l("-100", "0")], "ETB", false, { type: "percent", value: "10" }))).toEqual(["50.00", "350.00", "0.00", "350.00"]);
    expect(s(discountedAmounts([l("600")], "ETB", false, null))).toEqual(["0.00", "600.00", "90.00", "690.00"]);
    expect(toString(discountedAmounts([l("600")], "ETB", false, { type: "percent", value: "" }).discount)).toBe("0.00");
  });
});

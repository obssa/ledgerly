import { describe, expect, it } from "vitest";
import { documentAmounts, lineAmounts, parse, roundToCurrency, toString } from "./decimal";
import { formatAmount, toEthiopian } from "./format";

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
    expect(toString(doc.subtotal)).toBe("436300.0000");
    expect(toString(doc.tax)).toBe("65445.0000");
    expect(toString(doc.total)).toBe("501745.0000");
  });

  it("applies discount before tax", () => {
    const l = lineAmounts({ quantity: "3", unitPrice: "100", discountRate: "0.10", taxRate: "0.16" }, "KES");
    expect([toString(l.subtotal), toString(l.tax), toString(l.total)]).toEqual(["270.0000", "43.2000", "313.2000"]);
  });

  it("splits tax-inclusive prices without losing a cent", () => {
    const l = lineAmounts({ quantity: "1", unitPrice: "99.99", taxRate: "0.15" }, "ETB", true);
    expect(toString(l.subtotal + l.tax)).toBe("99.9900");
    expect(toString(l.subtotal)).toBe("86.9500");
  });

  it("rounds zero-decimal currencies to whole units", () => {
    const l = lineAmounts({ quantity: "3", unitPrice: "1999", taxRate: "0.18" }, "UGX");
    expect(toString(l.tax)).toBe("1079.0000");
  });

  it("never uses floats: 0.1 + 0.2 is exactly 0.3", () => {
    expect(toString(parse("0.1") + parse("0.2"))).toBe("0.3000");
  });

  it("rounds half away from zero", () => {
    expect(toString(roundToCurrency(parse("10.005"), "ETB"))).toBe("10.0100");
    expect(toString(roundToCurrency(parse("-10.005"), "ETB"))).toBe("-10.0100");
  });
});

describe("formatting", () => {
  it("groups thousands without float conversion", () => {
    expect(formatAmount("501745.0000", "ETB")).toBe("501,745.00");
    expect(formatAmount("1234567.5", "XOF")).toBe("1,234,568");
  });

  it("converts to the Ethiopian calendar", () => {
    expect(toEthiopian(new Date("2026-11-02T00:00:00Z")).label).toBe("ጥቅምት 23, 2019 ዓ.ም");
    const newYear = toEthiopian(new Date("2026-09-11T00:00:00Z"));
    expect([newYear.year, newYear.month, newYear.day]).toEqual([2019, 1, 1]);
    const pagume = toEthiopian(new Date("2023-09-11T00:00:00Z"));
    expect([pagume.year, pagume.month, pagume.day]).toEqual([2015, 13, 6]);
  });
});

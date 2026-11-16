import { describe, expect, it } from "vitest";
import { amountInWords, numberInWords } from "./words";

describe("numberInWords", () => {
  it("writes whole numbers the way a cheque does", () => {
    expect(numberInWords("0")).toBe("zero");
    expect(numberInWords("7")).toBe("seven");
    expect(numberInWords("15")).toBe("fifteen");
    expect(numberInWords("42")).toBe("forty-two");
    expect(numberInWords("100")).toBe("one hundred");
    expect(numberInWords("1005")).toBe("one thousand and five");
    expect(numberInWords("1250")).toBe("one thousand two hundred and fifty");
    expect(numberInWords("118640")).toBe("one hundred and eighteen thousand six hundred and forty");
    expect(numberInWords("2000000")).toBe("two million");
    expect(numberInWords("1000001")).toBe("one million and one");
    expect(numberInWords("007")).toBe("seven");
  });
});

describe("amountInWords", () => {
  it("names the currency and the cents, and ends with only", () => {
    expect(amountInWords("1250.50", "ETB")).toBe("One thousand two hundred and fifty birr and 50 cents only");
    expect(amountInWords("155,000.00", "ETB")).toBe("One hundred and fifty-five thousand birr only");
    expect(amountInWords("1000", "USD")).toBe("One thousand US dollars only");
    expect(amountInWords("3.05", "XOF")).toBe("Three XOF and 05 cents only");
    expect(() => amountInWords("-1", "ETB")).toThrow();
  });
});

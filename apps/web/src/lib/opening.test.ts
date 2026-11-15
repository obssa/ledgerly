import { describe, expect, it } from "vitest";
import { openingLines, openingTotals } from "./opening";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("openingLines", () => {
  it("sends one line per account with an amount and leaves empty rows out", () => {
    expect(openingLines(form([
      ["debit:bank", "50,000.00"], ["credit:bank", ""],
      ["debit:vat", ""], ["credit:vat", "5000"],
      ["debit:empty", "0"], ["credit:empty", "0.00"],
      ["version", "x"],
    ]))).toEqual([
      { account_id: "bank", debit: "50000.00" },
      { account_id: "vat", credit: "5000" },
    ]);
  });

  it("passes on a value that is not a number, so the API can name the field", () => {
    expect(openingLines(form([["debit:bank", "12a"]]))).toEqual([{ account_id: "bank", debit: "12a" }]);
  });
});

describe("openingTotals", () => {
  it("adds up exactly and shows the difference that goes to opening balance equity", () => {
    expect(openingTotals([{ debit: "0.10", credit: "" }, { debit: "0.20", credit: "" }, { debit: "", credit: "1,000.05" }])).toEqual({
      debit: "0.30", credit: "1000.05", difference: "-999.75",
    });
  });

  it("counts a half-typed field as zero", () => {
    expect(openingTotals([{ debit: "12.", credit: "abc" }])).toEqual({ debit: "0.00", credit: "0.00", difference: "0.00" });
  });
});

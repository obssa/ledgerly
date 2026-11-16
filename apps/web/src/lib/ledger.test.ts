import { describe, expect, it } from "vitest";
import { defaultPeriod, drCr, trialTotals } from "./ledger";

describe("drCr", () => {
  it("reads debit minus credit as an amount and its side", () => {
    expect(drCr("1250.50")).toEqual({ amount: "1250.50", side: "Dr" });
    expect(drCr("-300.00")).toEqual({ amount: "300.00", side: "Cr" });
    expect(drCr("0.00")).toEqual({ amount: "0.00", side: "" });
    expect(drCr("")).toEqual({ amount: "0.00", side: "" });
  });
});

describe("trialTotals", () => {
  it("adds debits and credits exactly and says whether they agree", () => {
    expect(trialTotals([{ debit: "1000.10", credit: "0.00" }, { debit: "0.00", credit: "1000.10" }])).toEqual({ debit: "1000.10", credit: "1000.10", balanced: true });
    expect(trialTotals([{ debit: "1", credit: "0" }]).balanced).toBe(false);
  });
});

describe("defaultPeriod", () => {
  it("runs from the first day of the year to today", () => {
    expect(defaultPeriod("2026-11-16")).toEqual({ from: "2026-01-01", to: "2026-11-16" });
  });
});

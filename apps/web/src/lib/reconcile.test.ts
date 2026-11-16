import { describe, expect, it } from "vitest";
import { reconcileTotals, reconciliationBody } from "./reconcile";

describe("reconcileTotals", () => {
  it("adds the ticked entries to the opening balance and shows what is left to explain", () => {
    const lines = [
      { amount: "1000.00", cleared: true },
      { amount: "-100.00", cleared: true },
      { amount: "-115.00", cleared: true },
      { amount: "10.00", cleared: false },
    ];
    expect(reconcileTotals("0.00", "795.00", lines)).toEqual({ clearedIn: "1000.00", clearedOut: "215.00", clearedBalance: "785.00", difference: "10.00" });
    expect(reconcileTotals("0.00", "795.00", lines.map((l) => ({ ...l, cleared: true }))).difference).toBe("0.00");
  });

  it("reads an ending balance typed with separators, and treats one being typed as zero", () => {
    expect(reconcileTotals("1,000.00", "1,250.50", [{ amount: "250.50", cleared: true }]).difference).toBe("0.00");
    expect(reconcileTotals("0.00", "-", [{ amount: "-5.00", cleared: true }]).difference).toBe("5.00");
  });
});

describe("reconciliationBody", () => {
  it("sends the date, the ending balance without separators and each ticked entry once", () => {
    const f = new FormData();
    f.append("statement_date", "2026-11-30");
    f.append("ending_balance", "12,500.00");
    f.append("cleared", "a");
    f.append("cleared", "b");
    f.append("cleared", "a");
    expect(reconciliationBody(f)).toEqual({ statement_date: "2026-11-30", ending_balance: "12500.00", cleared: ["a", "b"] });
    expect(reconciliationBody(new FormData())).toEqual({ cleared: [] });
  });
});

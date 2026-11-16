import { describe, expect, it } from "vitest";
import { byType, naturalBalance } from "./chart";

describe("naturalBalance", () => {
  it("shows credit-normal accounts with the sign turned", () => {
    expect(naturalBalance("asset", "1250.00")).toBe("1250.00");
    expect(naturalBalance("expense", "-15.00")).toBe("-15.00");
    expect(naturalBalance("liability", "-5000.00")).toBe("5000.00");
    expect(naturalBalance("income", "-1200.50")).toBe("1200.50");
    expect(naturalBalance("equity", "300.00")).toBe("-300.00");
    expect(naturalBalance("income", "0.00")).toBe("0.00");
    expect(naturalBalance("liability", "-0.00")).toBe("0.00");
  });
});

describe("byType", () => {
  it("groups in balance sheet then income statement order and leaves out empty groups", () => {
    const groups = byType([
      { type: "expense" as const, code: "6100" }, { type: "asset" as const, code: "1100" }, { type: "income" as const, code: "4000" }, { type: "asset" as const, code: "1000" },
    ]);
    expect(groups.map((g) => [g.label, g.accounts.map((a) => a.code)])).toEqual([["Assets", ["1100", "1000"]], ["Income", ["4000"]], ["Expenses", ["6100"]]]);
  });
});

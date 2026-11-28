import { describe, expect, it } from "vitest";
import { byType, nextAccountCode, naturalBalance } from "./chart";

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

describe("nextAccountCode", () => {
  const chart = ["1400", "1500", "4000", "4100", "4900", "4950", "4960", "5000", "5100", "6000", "6900", "6950", "6980", "6990", "7000"];
  it("takes the next ten after the highest code in the range", () => {
    expect(nextAccountCode(chart, 4000, 4899)).toBe("4110");
    expect(nextAccountCode(chart, 4900, 4999)).toBe("4970");
    expect(nextAccountCode(chart, 1500, 1599)).toBe("1510");
  });
  it("starts the range when nothing is in it, and ignores codes that are not numbers", () => {
    expect(nextAccountCode(["4000", "SALES-1", "6100.5"], 6000, 6999)).toBe("6000");
  });
  it("fills the first free number when the tens are used up, and gives up on a full range", () => {
    expect(nextAccountCode(chart, 6000, 6999)).toBe("6001");
    expect(nextAccountCode(["10", "11", "12"], 10, 12)).toBeNull();
  });
});

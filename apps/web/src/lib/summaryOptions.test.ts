import { describe, expect, it } from "vitest";
import { perUnit, percentChange } from "./decimal";
import { shiftDay } from "./periods";

describe("the summary reports' extra columns", () => {
  it("work out an average cost or price exactly, half-up, and nothing for no units", () => {
    expect(perUnit("450.00", "3.00")).toBe("150.00");
    expect(perUnit("100.00", "3.00")).toBe("33.33");
    expect(perUnit("200.00", "3.00")).toBe("66.67");
    expect(perUnit("-90.00", "4.00")).toBe("-22.50");
    expect(perUnit("50.00", "0.00")).toBeNull();
  });

  it("give a change against the earlier figure, and a dash with nothing to compare", () => {
    expect(percentChange("1125.00", "1000.00")).toBe("+12.5%");
    expect(percentChange("970.00", "1000.00")).toBe("−3.0%");
    expect(percentChange("-50.00", "-100.00")).toBe("+50.0%");
    expect(percentChange("10.00", "0.00")).toBe("—");
    expect(percentChange("10.00", null)).toBe("—");
  });

  it("compare a balance sheet with a month, quarter or year earlier in the company's calendar", () => {
    expect(shiftDay("2026-11-30", -1, "gregorian")).toBe("2026-10-30");
    expect(shiftDay("2026-05-31", -3, "gregorian")).toBe("2026-02-28");
    expect(shiftDay("2028-02-29", -12, "gregorian")).toBe("2027-02-28");
    // Hidar 21, 2019 a month earlier is Tikimt 21, 2019 (31 October 2026).
    expect(shiftDay("2026-11-30", -1, "ethiopian")).toBe("2026-10-31");
  });
});

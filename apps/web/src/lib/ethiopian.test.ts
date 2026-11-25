import { describe, expect, it } from "vitest";
import { daysInMonth, formatEthiopian, fromGregorian, toGregorian } from "./ethiopian";

// The same dates as apps/api/tests/Unit/FiscalCalendarTest.php: the web and the API must agree.
describe("Ethiopian calendar", () => {
  it("matches known dates", () => {
    expect(fromGregorian("2026-09-11")).toEqual({ year: 2019, month: 1, day: 1 });
    expect(fromGregorian("2026-11-02")).toEqual({ year: 2019, month: 2, day: 23 });
    expect(fromGregorian("2023-09-11")).toEqual({ year: 2015, month: 13, day: 6 });
    expect(formatEthiopian("2026-11-02")).toBe("Tikimt 23, 2019");
    expect(toGregorian(2018, 11, 1)).toBe("2026-07-08");   // 1 Hamle 2018, the tax year's start
  });

  it("converts every day of ten years there and back", () => {
    for (let d = new Date(Date.UTC(2020, 0, 1)); d < new Date(Date.UTC(2030, 0, 1)); d.setUTCDate(d.getUTCDate() + 1)) {
      const iso = d.toISOString().slice(0, 10);
      const e = fromGregorian(iso);
      expect(toGregorian(e.year, e.month, e.day)).toBe(iso);
    }
  });

  it("gives Pagume five days, six when the year divided by 4 leaves 3", () => {
    expect(daysInMonth(2018, 13)).toBe(5);
    expect(daysInMonth(2019, 13)).toBe(6);
    expect(daysInMonth(2019, 1)).toBe(30);
    expect(() => toGregorian(2018, 13, 6)).toThrow(RangeError);
  });
});

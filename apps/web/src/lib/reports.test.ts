import { describe, expect, it } from "vitest";
import { STOCK_BANDS, isDate, periodLabel, previousPeriod, showRatio, columnLabel, customOptions } from "./reports";

describe("previousPeriod", () => {
  it("is the same length, ending the day before", () => {
    expect(previousPeriod("2026-10-01", "2026-10-31")).toEqual({ from: "2026-08-31", to: "2026-09-30" });
    expect(previousPeriod("2026-01-01", "2026-12-31")).toEqual({ from: "2025-01-01", to: "2025-12-31" });
    expect(previousPeriod("2026-11-17", "2026-11-17")).toEqual({ from: "2026-11-16", to: "2026-11-16" });
  });
});

describe("periodLabel", () => {
  it("names a whole month, otherwise both ends", () => {
    expect(periodLabel("2026-10-01", "2026-10-31")).toBe("October 2026");
    expect(periodLabel("2026-01-01", "2026-11-17")).toBe("1 Jan 2026 to 17 Nov 2026");
  });
});

describe("isDate and showRatio", () => {
  it("accepts real dates only, and shows a missing ratio as a dash", () => {
    expect(isDate("2026-02-28")).toBe(true);
    expect(isDate("2026-13-01")).toBe(false);
    expect(isDate("yesterday")).toBe(false);
    expect(showRatio(null, "%")).toBe("—");
    expect(showRatio("40.0", "%")).toBe("40.0%");
  });
});

describe("STOCK_BANDS", () => {
  it("names every band the API gives, slow-moving and dead stock marked", () => {
    expect(Object.keys(STOCK_BANDS)).toEqual(["0-30", "31-90", "91-180", "over-180", "never"]);
    expect([STOCK_BANDS["91-180"].note, STOCK_BANDS["over-180"].note, STOCK_BANDS.never.note, STOCK_BANDS["0-30"].note]).toEqual(["slow-moving", "dead stock", "dead stock", undefined]);
  });
});

describe("custom report", () => {
  it("reads only known choices from the address", () => {
    expect(customOptions({})).toEqual({ rows: "account", columns: "total", measure: "natural", account_type: undefined, project_id: undefined, location_id: undefined, class_id: undefined });
    expect(customOptions({ rows: "project", columns: "month", measure: "debit", account_type: "income", project_id: "01a1e0e3-abce-720d-93b3-579d7d6af584" }))
      .toMatchObject({ rows: "project", columns: "month", measure: "debit", account_type: "income", project_id: "01a1e0e3-abce-720d-93b3-579d7d6af584" });
    expect(customOptions({ rows: "a.id; DROP TABLE", measure: "sum(debit)", project_id: "x" })).toMatchObject({ rows: "account", measure: "natural", project_id: undefined });
  });
  it("names months and quarters", () => {
    expect(columnLabel("2026-11")).toBe("Nov 2026");
    expect(columnLabel("2026-Q4")).toBe("Q4 2026");
    expect(columnLabel("Total")).toBe("Total");
  });
});

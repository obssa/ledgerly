import { describe, expect, it } from "vitest";
import { savedReportBody, savedReportUrl } from "./saved-reports";

describe("savedReportUrl", () => {
  it("opens a period report for the dates it covers, with its options", () => {
    expect(savedReportUrl({ report: "sales", covers: { from: "2026-11-01", to: "2026-11-17" }, options: { group: "salesperson" } }))
      .toBe("/reports/sales?from=2026-11-01&to=2026-11-17&group=salesperson");
    expect(savedReportUrl({ report: "profit-and-loss", covers: { from: "2026-10-01", to: "2026-10-31" }, options: {} })).toBe("/reports?from=2026-10-01&to=2026-10-31");
  });
  it("opens a report of one date at that date, and an unknown report at the reports page", () => {
    expect(savedReportUrl({ report: "balance-sheet", covers: { as_of: "2026-10-31" }, options: {} })).toBe("/reports/balance-sheet?as_of=2026-10-31");
    expect(savedReportUrl({ report: "stock-ageing", covers: { as_of: "2026-11-17" }, options: { warehouse_id: "w1", evil: "x" } }))
      .toBe("/reports/stock-ageing?as_of=2026-11-17&warehouse_id=w1");
    expect(savedReportUrl({ report: "payroll", covers: {}, options: {} })).toBe("/reports");
  });
});

describe("savedReportBody", () => {
  it("sends dates only for a custom period, and only the options given", () => {
    const f = new FormData();
    for (const [k, v] of [["name", " Monthly sales "], ["report", "sales"], ["period", "last_month"], ["schedule", "monthly"], ["group", "item"], ["from", "2026-01-01"], ["kind", ""]]) f.append(k, v);
    expect(savedReportBody(f)).toEqual({ name: "Monthly sales", report: "sales", period: "last_month", schedule: "monthly", options: { group: "item" } });
    f.set("period", "custom");
    f.set("to", "2026-03-31");
    expect(savedReportBody(f)).toMatchObject({ period: "custom", from: "2026-01-01", to: "2026-03-31" });
  });
});

describe("a saved custom report", () => {
  it("keeps its rows, columns, figure and filters", () => {
    const f = new FormData();
    for (const [k, v] of [["name", "Costs by project"], ["report", "custom"], ["period", "this_quarter"], ["rows", "project"], ["columns", "month"],
      ["measure", "natural"], ["account_type", "expense"]]) f.set(k, v);
    expect(savedReportBody(f)).toEqual({ name: "Costs by project", report: "custom", period: "this_quarter", schedule: "none",
      options: { rows: "project", columns: "month", measure: "natural", account_type: "expense" } });
  });
});

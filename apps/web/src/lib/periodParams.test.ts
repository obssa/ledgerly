import { describe, expect, it } from "vitest";
import { apiDates, pickAsOf, pickRange } from "./periodParams";
import type { PeriodContext } from "./periods";

const ctx: PeriodContext = { today: "2026-11-26", calendar: "gregorian", fiscalStartMonth: 7 };

describe("which period a page shows", () => {
  it("nothing chosen: the page's default preset", () => {
    expect(pickRange({}, ctx, "this_fiscal_year_to_date")).toEqual({ preset: "this_fiscal_year_to_date", from: "2026-07-01", to: "2026-11-26" });
    expect(pickRange({}, ctx, "all")).toEqual({ preset: "all", from: null, to: null });
  });

  it("a preset picked from the list wins over the dates still in the form", () => {
    expect(pickRange({ range: "last_month", shown: "this_fiscal_year_to_date", from: "2026-07-01", to: "2026-11-26" }, ctx, "all"))
      .toEqual({ preset: "last_month", from: "2026-10-01", to: "2026-10-31" });
  });

  it("the same preset with other dates typed: Custom with those dates", () => {
    expect(pickRange({ range: "last_month", shown: "last_month", from: "2026-10-05", to: "2026-10-31" }, ctx, "all"))
      .toEqual({ preset: "custom", from: "2026-10-05", to: "2026-10-31" });
    expect(pickRange({ range: "last_month", shown: "last_month", from: "2026-10-01", to: "2026-10-31" }, ctx, "all"))
      .toEqual({ preset: "last_month", from: "2026-10-01", to: "2026-10-31" });
  });

  it("dates without a preset (a link, a drill-down) are named after the preset they match", () => {
    expect(pickRange({ from: "2026-10-01", to: "2026-10-31" }, ctx, "all")).toEqual({ preset: "last_month", from: "2026-10-01", to: "2026-10-31" });
    expect(pickRange({ from: "2026-10-03", to: "2026-10-09" }, ctx, "all")).toEqual({ preset: "custom", from: "2026-10-03", to: "2026-10-09" });
  });

  it("a preset the page does not allow, or nonsense, is ignored", () => {
    expect(pickRange({ range: "all" }, ctx, "last_month", ["last_month", "this_month", "custom"])).toEqual({ preset: "last_month", from: "2026-10-01", to: "2026-10-31" });
    expect(pickRange({ range: "drop table", from: "x" }, ctx, "today")).toEqual({ preset: "today", from: "2026-11-26", to: "2026-11-26" });
  });

  it("open ends become the widest dates for an API that wants both", () => {
    expect(apiDates({ from: null, to: null })).toEqual({ from: "1900-01-01", to: "2999-12-31" });
    expect(apiDates({ from: "2026-10-27", to: null })).toEqual({ from: "2026-10-27", to: "2999-12-31" });
  });
});

describe("which date an as-of report shows", () => {
  it("defaults to today, follows presets, and keeps a typed date", () => {
    expect(pickAsOf({}, ctx)).toEqual({ preset: "today", asOf: "2026-11-26" });
    expect(pickAsOf({ range: "end_of_last_month", shown: "today", as_of: "2026-11-26" }, ctx)).toEqual({ preset: "end_of_last_month", asOf: "2026-10-31" });
    // The same preset still selected but another date typed: that date.
    expect(pickAsOf({ range: "today", shown: "today", as_of: "2026-09-15" }, ctx)).toEqual({ preset: "custom", asOf: "2026-09-15" });
    expect(pickAsOf({ as_of: "2026-09-30" }, ctx)).toEqual({ preset: "end_of_last_quarter", asOf: "2026-09-30" });
  });
});

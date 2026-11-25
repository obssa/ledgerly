import { describe, expect, it } from "vitest";
import { asOfLabel, matchRange, RANGE_PRESETS, rangeLabel, resolveAsOf, resolveRange, type PeriodContext, type RangePreset } from "./periods";

// Thursday 26 November 2026, a company whose fiscal year starts in July (none set up yet).
const greg: PeriodContext = { today: "2026-11-26", calendar: "gregorian", fiscalStartMonth: 7 };
const r = (preset: RangePreset, ctx = greg) => resolveRange(preset, ctx);

describe("report periods, Gregorian calendar", () => {
  it("days and weeks (Monday to Sunday)", () => {
    expect(r("all")).toEqual({ from: null, to: null });
    expect(r("custom")).toBeNull();
    expect(r("today")).toEqual({ from: "2026-11-26", to: "2026-11-26" });
    expect(r("yesterday")).toEqual({ from: "2026-11-25", to: "2026-11-25" });
    expect(r("this_week")).toEqual({ from: "2026-11-23", to: "2026-11-29" });
    expect(r("this_week_to_date")).toEqual({ from: "2026-11-23", to: "2026-11-26" });
    expect(r("last_week")).toEqual({ from: "2026-11-16", to: "2026-11-22" });
    expect(r("last_week_to_date")).toEqual({ from: "2026-11-16", to: "2026-11-19" });
    expect(r("next_week")).toEqual({ from: "2026-11-30", to: "2026-12-06" });
    expect(r("next_4_weeks")).toEqual({ from: "2026-11-26", to: "2026-12-23" });
  });

  it("months and quarters", () => {
    expect(r("this_month")).toEqual({ from: "2026-11-01", to: "2026-11-30" });
    expect(r("this_month_to_date")).toEqual({ from: "2026-11-01", to: "2026-11-26" });
    expect(r("last_month")).toEqual({ from: "2026-10-01", to: "2026-10-31" });
    expect(r("last_month_to_date")).toEqual({ from: "2026-10-01", to: "2026-10-26" });
    expect(r("next_month")).toEqual({ from: "2026-12-01", to: "2026-12-31" });
    expect(r("this_quarter")).toEqual({ from: "2026-10-01", to: "2026-12-31" });
    expect(r("this_quarter_to_date")).toEqual({ from: "2026-10-01", to: "2026-11-26" });
    expect(r("last_quarter")).toEqual({ from: "2026-07-01", to: "2026-09-30" });
    expect(r("last_quarter_to_date")).toEqual({ from: "2026-07-01", to: "2026-08-26" });
    expect(r("next_quarter")).toEqual({ from: "2027-01-01", to: "2027-03-31" });
  });

  it("years", () => {
    expect(r("this_year")).toEqual({ from: "2026-01-01", to: "2026-12-31" });
    expect(r("this_year_to_date")).toEqual({ from: "2026-01-01", to: "2026-11-26" });
    expect(r("this_year_to_last_month")).toEqual({ from: "2026-01-01", to: "2026-10-31" });
    expect(r("last_year")).toEqual({ from: "2025-01-01", to: "2025-12-31" });
    expect(r("last_year_to_date")).toEqual({ from: "2025-01-01", to: "2025-11-26" });
    expect(r("next_year")).toEqual({ from: "2027-01-01", to: "2027-12-31" });
  });

  it("fiscal years and quarters from the start month", () => {
    expect(r("this_fiscal_year")).toEqual({ from: "2026-07-01", to: "2027-06-30" });
    expect(r("this_fiscal_year_to_date")).toEqual({ from: "2026-07-01", to: "2026-11-26" });
    expect(r("this_fiscal_year_to_last_month")).toEqual({ from: "2026-07-01", to: "2026-10-31" });
    expect(r("last_fiscal_year")).toEqual({ from: "2025-07-01", to: "2026-06-30" });
    expect(r("last_fiscal_year_to_date")).toEqual({ from: "2025-07-01", to: "2025-11-26" });
    expect(r("this_fiscal_quarter")).toEqual({ from: "2026-10-01", to: "2026-12-31" });
    expect(r("this_fiscal_quarter_to_date")).toEqual({ from: "2026-10-01", to: "2026-11-26" });
    expect(r("last_fiscal_quarter")).toEqual({ from: "2026-07-01", to: "2026-09-30" });
  });

  it("fiscal years as set up win over the start month (a short first year)", () => {
    const ctx = { ...greg, fiscalYears: [{ starts_on: "2026-03-01", ends_on: "2026-12-31" }] };
    expect(r("this_fiscal_year", ctx)).toEqual({ from: "2026-03-01", to: "2026-12-31" });
    expect(r("this_fiscal_year_to_date", ctx)).toEqual({ from: "2026-03-01", to: "2026-11-26" });
  });

  it("rolling and since", () => {
    expect(r("last_7_days")).toEqual({ from: "2026-11-20", to: "2026-11-26" });
    expect(r("last_30_days")).toEqual({ from: "2026-10-28", to: "2026-11-26" });
    expect(r("last_90_days")).toEqual({ from: "2026-08-29", to: "2026-11-26" });
    expect(r("last_365_days")).toEqual({ from: "2025-11-27", to: "2026-11-26" });
    expect(r("last_12_months")).toEqual({ from: "2025-11-01", to: "2026-10-31" });
    expect(r("since_30_days_ago")).toEqual({ from: "2026-10-27", to: null });
    expect(r("since_60_days_ago")).toEqual({ from: "2026-09-27", to: null });
    expect(r("since_90_days_ago")).toEqual({ from: "2026-08-28", to: null });
    expect(r("since_365_days_ago")).toEqual({ from: "2025-11-26", to: null });
  });

  it("edges: a short month, and the first month of a year", () => {
    expect(r("last_month_to_date", { ...greg, today: "2026-03-31" })).toEqual({ from: "2026-02-01", to: "2026-02-28" });
    expect(r("last_year_to_date", { ...greg, today: "2028-02-29" })).toEqual({ from: "2027-01-01", to: "2027-02-28" });
    // January: no finished month this year yet, so the whole of last year.
    expect(r("this_year_to_last_month", { ...greg, today: "2026-01-15" })).toEqual({ from: "2025-01-01", to: "2025-12-31" });
    expect(r("this_fiscal_year_to_last_month", { ...greg, today: "2026-07-03" })).toEqual({ from: "2025-07-01", to: "2026-06-30" });
  });

  it("as-of dates", () => {
    expect(resolveAsOf("today", greg)).toBe("2026-11-26");
    expect(resolveAsOf("yesterday", greg)).toBe("2026-11-25");
    expect(resolveAsOf("end_of_last_week", greg)).toBe("2026-11-22");
    expect(resolveAsOf("end_of_last_month", greg)).toBe("2026-10-31");
    expect(resolveAsOf("end_of_last_quarter", greg)).toBe("2026-09-30");
    expect(resolveAsOf("end_of_last_year", greg)).toBe("2025-12-31");
    expect(resolveAsOf("end_of_last_fiscal_year", greg)).toBe("2026-06-30");
    expect(resolveAsOf("custom", greg)).toBeNull();
  });

  it("every preset gives a from that is not after its to", () => {
    for (const { key } of RANGE_PRESETS) {
      for (const today of ["2026-01-01", "2026-02-28", "2026-07-01", "2026-12-31", "2028-02-29"]) {
        const range = r(key, { ...greg, today });
        if (range?.from && range.to) expect(range.from <= range.to, `${key} on ${today}`).toBe(true);
      }
    }
  });
});

// Hidar 17, 2019 (26 November 2026), an Ethiopian-calendar company with the tax year from Hamle (month 11).
const eth: PeriodContext = { today: "2026-11-26", calendar: "ethiopian", fiscalStartMonth: 11 };

describe("report periods, Ethiopian calendar", () => {
  it("months are Ethiopian months", () => {
    expect(r("this_month", eth)).toEqual({ from: "2026-11-10", to: "2026-12-09" });        // Hidar 2019
    expect(r("this_month_to_date", eth)).toEqual({ from: "2026-11-10", to: "2026-11-26" });
    expect(r("last_month", eth)).toEqual({ from: "2026-10-11", to: "2026-11-09" });        // Tikimt 2019
    expect(resolveAsOf("end_of_last_month", eth)).toBe("2026-11-09");                       // Tikimt 30
  });

  it("quarters are three Ethiopian months; Pagume goes with Nehase", () => {
    expect(r("this_quarter", eth)).toEqual({ from: "2026-09-11", to: "2026-12-09" });      // Meskerem to Hidar 2019
    expect(r("last_quarter", eth)).toEqual({ from: "2026-06-08", to: "2026-09-10" });      // Sene to Nehase + Pagume 2018
  });

  it("the year is the Ethiopian year (2019 has a six-day Pagume)", () => {
    expect(r("this_year", eth)).toEqual({ from: "2026-09-11", to: "2027-09-11" });
    expect(r("last_year", eth)).toEqual({ from: "2025-09-11", to: "2026-09-10" });
  });

  it("the fiscal year runs Hamle to Sene, as FiscalCalendar says", () => {
    expect(r("this_fiscal_year", eth)).toEqual({ from: "2026-07-08", to: "2027-07-07" });
    expect(r("last_fiscal_year", eth)).toEqual({ from: "2025-07-08", to: "2026-07-07" });
    // Fiscal quarters from Hamle: Hamle–Meskerem, then Tikimt–Tahsas (Hidar is in the second).
    expect(r("this_fiscal_quarter", eth)).toEqual({ from: "2026-10-11", to: "2027-01-08" });
    expect(r("last_fiscal_quarter", eth)).toEqual({ from: "2026-07-08", to: "2026-10-10" });
  });

  it("a Pagume day belongs to Nehase's month", () => {
    const pagume = { ...eth, today: "2026-09-08" };   // Pagume 3, 2018
    expect(r("this_month", pagume)).toEqual({ from: "2026-08-07", to: "2026-09-10" });
  });
});

describe("words for a period", () => {
  it("in either calendar", () => {
    expect(rangeLabel({ from: "2026-07-01", to: "2026-11-26" }, "gregorian")).toBe("1 July 2026 – 26 November 2026");
    expect(rangeLabel({ from: "2026-11-10", to: "2026-11-26" }, "ethiopian")).toBe("Hidar 1, 2019 – Hidar 17, 2019");
    expect(rangeLabel({ from: null, to: null }, "gregorian")).toBe("All dates");
    expect(rangeLabel({ from: "2026-10-27", to: null }, "gregorian")).toBe("From 27 October 2026");
    expect(asOfLabel("2026-11-26", "gregorian")).toBe("As of 26 November 2026");
  });

  it("recognises a preset from its dates", () => {
    expect(matchRange({ from: "2026-10-01", to: "2026-10-31" }, greg)).toBe("last_month");
    expect(matchRange({ from: "2026-10-02", to: "2026-10-31" }, greg)).toBe("custom");
  });
});

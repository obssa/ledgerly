import { describe, expect, it } from "vitest";
import { columnsRequest, percentOf, pickDisplayBy } from "./pnlColumns";
import { splitRange } from "./periods";

describe("the profit and loss in columns", () => {
  it("cuts a period into the company's months, quarters and years, the ends clipped", () => {
    expect(splitRange({ from: "2026-07-08", to: "2026-10-15" }, "month", "gregorian")).toEqual([
      { label: "Jul 2026", from: "2026-07-08", to: "2026-07-31" }, { label: "Aug 2026", from: "2026-08-01", to: "2026-08-31" },
      { label: "Sep 2026", from: "2026-09-01", to: "2026-09-30" }, { label: "Oct 2026", from: "2026-10-01", to: "2026-10-15" },
    ]);
    expect(splitRange({ from: "2026-07-08", to: "2027-07-07" }, "quarter", "gregorian")?.map((s) => s.label)).toEqual(["Q3 2026", "Q4 2026", "Q1 2027", "Q2 2027", "Q3 2027"]);
    expect(splitRange({ from: "2026-01-01", to: "2027-12-31" }, "year", "gregorian")?.map((s) => [s.label, s.from, s.to]))
      .toEqual([["2026", "2026-01-01", "2026-12-31"], ["2027", "2027-01-01", "2027-12-31"]]);
  });

  it("uses Ethiopian months for an Ethiopian-calendar company, Pagume with Nehase", () => {
    // Meskerem 1, 2019 is 11 September 2026; Nehase and Pagume 2018 run from 7 August to 10 September 2026.
    const months = splitRange({ from: "2026-08-07", to: "2026-10-10" }, "month", "ethiopian");
    expect(months?.map((m) => [m.label, m.from, m.to])).toEqual([
      ["Nehase 2018", "2026-08-07", "2026-09-10"], ["Meskerem 2019", "2026-09-11", "2026-10-10"],
    ]);
  });

  it("asks for at most 60 columns, and nothing for a grouping that is not by period", () => {
    expect(columnsRequest("month", { from: "1900-01-01", to: "2026-11-26" }, "gregorian")).toBeNull();
    expect(columnsRequest("contact", { from: "2026-01-01", to: "2026-12-31" }, "gregorian")).toEqual({ by: "contact" });
    expect(columnsRequest("quarter", { from: "2026-01-01", to: "2026-12-31" }, "gregorian"))
      .toEqual({ by: "period", starts: "2026-01-01,2026-04-01,2026-07-01,2026-10-01", labels: ["Q1 2026", "Q2 2026", "Q3 2026", "Q4 2026"] });
    expect(pickDisplayBy("month")).toBe("month");
    expect(pickDisplayBy("DROP")).toBe("total");
  });

  it("gives each total its share of income, exactly", () => {
    expect(percentOf("250.00", "1000.00")).toBe("25.0%");
    expect(percentOf("-12.50", "1000.00")).toBe("−1.2%");
    expect(percentOf("1.00", "3.00")).toBe("33.3%");
    expect(percentOf("5.00", "0.00")).toBe("—");
  });
});

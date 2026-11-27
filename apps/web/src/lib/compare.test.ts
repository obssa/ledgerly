import { describe, expect, it } from "vitest";
import { amountChange, compareQuery, mergeRows, pctChange, periodColumnLabel, pickAsOfComparisons, pickDiff, pickRangeComparisons } from "./compare";
import { balanceSheetLines, lineCells, profitAndLossLines, trialBalanceLines } from "./compareLines";
import type { BalanceSheet, ProfitAndLoss } from "./api";
import type { PeriodContext } from "./periods";

const ctx: PeriodContext = { today: "2026-11-27", calendar: "gregorian", fiscalStartMonth: 7, dateFormat: "DD/MM/YYYY" };
const q3 = { from: "2026-07-01", to: "2026-09-30" };

describe("which periods a report is compared with", () => {
  it("quick choices, presets and custom dates, up to three", () => {
    const r = pickRangeComparisons({ c1: "previous_year", c2: "two_years_ago", c3: "custom", c3_from: "01/01/2026", c3_to: "31/03/2026" }, q3, ctx);
    expect(r.periods).toEqual([
      { from: "2025-07-01", to: "2025-09-30", label: "Q3 2025" },
      { from: "2024-07-01", to: "2024-09-30", label: "Q3 2024" },
      { from: "2026-01-01", to: "2026-03-31", label: "Q1 2026" },
    ]);
    expect(pickRangeComparisons({ c1: "previous_period" }, q3, ctx).periods[0]).toMatchObject({ from: "2026-04-01", to: "2026-06-30" });
    expect(pickRangeComparisons({ c1: "last_month" }, q3, ctx).periods[0]).toEqual({ from: "2026-10-01", to: "2026-10-31", label: "Oct 2026" });
    expect(pickRangeComparisons({ c1: "custom", c1_from: "05/10/2026", c1_to: "20/10/2026" }, q3, ctx).periods[0].label).toBe("05/10/2026 – 20/10/2026");
  });

  it("drops the impossible, the repeated and the report's own period", () => {
    expect(pickRangeComparisons({ c1: "custom", c1_from: "31/10/2026", c1_to: "01/10/2026" }, q3, ctx).periods).toEqual([]);
    expect(pickRangeComparisons({ c1: "previous_year", c2: "previous_year" }, q3, ctx).periods).toHaveLength(1);
    expect(pickRangeComparisons({ c1: "custom", c1_from: "2026-07-01", c1_to: "2026-09-30" }, q3, ctx).periods).toEqual([]);
    expect(pickRangeComparisons({ c1: "since_30_days_ago" }, q3, ctx).periods).toEqual([]);
    // An open period (All dates) has no previous period.
    expect(pickRangeComparisons({ c1: "previous_period" }, { from: null, to: null }, ctx).periods).toEqual([]);
  });

  it("older links and the report's default still work; none chosen is none", () => {
    expect(pickRangeComparisons({}, q3, ctx, ["previous_period"]).periods[0]).toMatchObject({ from: "2026-04-01" });
    expect(pickRangeComparisons({ compare: "year" }, q3, ctx, ["previous_period"]).periods[0]).toMatchObject({ from: "2025-07-01" });
    expect(pickRangeComparisons({ c1: "", c2: "", c3: "" }, q3, ctx, ["previous_period"]).periods).toEqual([]);
    expect(pickRangeComparisons({ c1: "none" }, q3, ctx, ["previous_period"]).periods).toEqual([]);
    expect(compareQuery(pickRangeComparisons({ c1: "" }, q3, ctx, ["previous_period"]).slots, "pct")).toEqual({ c1: "none", diff: "pct" });
  });

  it("a report at a date: earlier dates in the company's calendar, presets, custom", () => {
    const r = pickAsOfComparisons({ c1: "previous_year", c2: "end_of_last_fiscal_year", c3: "custom", c3_as_of: "31/12/2024" }, "2026-11-27", ctx);
    expect(r.dates.map((d) => d.asOf)).toEqual(["2025-11-27", "2026-06-30", "2024-12-31"]);
    expect(r.dates[0].label).toBe("27/11/2025");
    expect(pickAsOfComparisons({ compare: "quarter" }, "2026-11-30", ctx).dates[0].asOf).toBe("2026-08-30");
    expect(pickAsOfComparisons({ c1: "previous_month" }, "2026-03-31", ctx).dates[0].asOf).toBe("2026-02-28");
    expect(pickDiff({ diff: "both" }, "none")).toBe("both");
    expect(pickDiff({ diff: "sql" }, "pct")).toBe("pct");
  });

  it("column headers name whole months, quarters and years, in the company's calendar", () => {
    expect(periodColumnLabel({ from: "2025-01-01", to: "2025-12-31" }, ctx)).toBe("2025");
    expect(periodColumnLabel({ from: "2025-07-01", to: "2026-06-30" }, ctx)).toBe("01/07/2025 – 30/06/2026");
    expect(periodColumnLabel({ from: "2026-11-10", to: "2026-12-09" }, { ...ctx, calendar: "ethiopian" })).toBe("Hidar 2019");
  });
});

describe("a statement with a column per period", () => {
  const pl = (income: [string, string, string][], expenses: [string, string, string][], net: string): ProfitAndLoss => {
    const tot = (v: string) => ({ current: v, previous: null });
    const sum = (rows: [string, string, string][]) => rows.reduce((a, r) => a + Number(r[2]), 0).toFixed(2);
    return {
      sections: { income: income.map(([code, name, v]) => ({ code, name, current: v, previous: null })), cost_of_sales: [],
        expenses: expenses.map(([code, name, v]) => ({ code, name, current: v, previous: null })) },
      totals: { income: tot(sum(income)), cost_of_sales: tot("0.00"), gross_profit: tot(sum(income)), expenses: tot(sum(expenses)), net_profit: tot(net) },
    } as ProfitAndLoss;
  };

  it("joins accounts across periods, nothing where a period had none", () => {
    const lines = profitAndLossLines([pl([["4000", "Sales", "1000.00"]], [["6100", "Rent", "300.00"]], "700.00"),
      pl([["4000", "Sales", "800.00"], ["4100", "Services", "50.00"]], [], "850.00")]);
    expect(lines.find((l) => l.code === "4100")?.values).toEqual([null, "50.00"]);
    expect(lines.find((l) => l.code === "6100")?.values).toEqual(["300.00", null]);
    expect(lines.find((l) => l.key === "t:net_profit")?.values).toEqual(["700.00", "850.00"]);
    expect(amountChange(["700.00", "850.00"])).toBe("-150.00");
    expect(amountChange(["300.00", null])).toBe("300.00");
    expect(pctChange(["700.00", "850.00"])).toBe("−17.6%");
    expect(pctChange(["300.00", null])).toBe("—");
  });

  it("writes the same lines as a file: code, account, a column per period, the change columns", () => {
    const lines = profitAndLossLines([pl([["4000", "Sales", "1000.00"]], [], "1000.00"), pl([["4000", "Sales", "800.00"]], [], "800.00")]);
    const rows = lineCells(lines, ["Q3 2026", "Q3 2025"], "both");
    expect(rows[0]).toEqual(["Code", "Account", "Q3 2026", "Q3 2025", "Change", "% change"]);
    expect(rows).toContainEqual(["4000", "Sales", "1000.00", "800.00", "200.00", "+25.0%"]);
    expect(rows).toContainEqual([null, "Income"]);
    expect(lineCells(lines, ["Amount"], "both")[0]).toEqual(["Code", "Account", "Amount"]);
  });

  it("the balance sheet and trial balance at several dates", () => {
    const bs = (cash: string, capital: string): BalanceSheet => ({
      sections: { assets: [{ code: "1110", name: "Cash", amount: cash }], liabilities: [], equity: [{ code: "3000", name: "Capital", amount: capital }] },
      equation: { assets: cash, liabilities: "0.00", equity: capital, profit_to_date: "0.00", liabilities_and_equity: cash, difference: "0.00", balanced: true },
    });
    const lines = balanceSheetLines([bs("500.00", "500.00"), bs("400.00", "400.00")]);
    expect(lines.find((l) => l.code === "1110")?.values).toEqual(["500.00", "400.00"]);
    expect(lines.at(-1)?.values).toEqual(["500.00", "400.00"]);
    const tb = trialBalanceLines([[{ account_id: "a", code: "1110", name: "Cash", type: "asset", debit: "500.00", credit: "0.00" }],
      [{ account_id: "b", code: "2100", name: "Payables", type: "liability", debit: "0.00", credit: "40.00" }]]);
    expect(tb.map((r) => [r.code, r.values])).toEqual([["1110", ["500.00", "0.00", null, null]], ["2100", [null, null, "0.00", "40.00"]]]);
    expect(mergeRows([[{ k: "a" }], [{ k: "b" }, { k: "a" }]], (x) => x.k, () => "1.00", (x) => x.k).map((l) => l.key)).toEqual(["a", "b"]);
  });
});

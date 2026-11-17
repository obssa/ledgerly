import { describe, expect, it } from "vitest";
import { parseLines } from "./invoice";
import { projectBody, timeBody, milestoneTotal } from "./projects";

describe("projectBody", () => {
  it("sends the code only when the project is new, and empty fields as null", () => {
    const f = new FormData();
    for (const [k, v] of [["code", " P-7 "], ["name", "Bole road"], ["status", "active"], ["billing", "fixed_fee"], ["customer_id", ""], ["contract_value", "1,250,000.00"], ["budget_cost", ""]]) f.append(k, v);
    expect(projectBody(f, true)).toEqual({ code: "P-7", name: "Bole road", status: "active", billing: "fixed_fee", customer_id: null, starts_on: null, ends_on: null, notes: null,
      contract_value: "1250000.00", budget_income: null, budget_cost: null, hourly_rate: null });
    expect(projectBody(f, false)).not.toHaveProperty("code");
  });
});

describe("parseLines with a project", () => {
  it("keeps a line's project and leaves it out when empty", () => {
    const lines = parseLines(JSON.stringify([
      { description: "Tiles", quantity: "1", unit_price: "800", account_id: "a", tax_rate_id: null, project_id: "p1" },
      { description: "Office", quantity: "1", unit_price: "10", account_id: "a", tax_rate_id: null, project_id: "" },
    ]));
    expect(lines[0].project_id).toBe("p1");
    expect(lines[1]).not.toHaveProperty("project_id");
  });
});

describe("timeBody", () => {
  it("reads a comma as the decimal point and billable from the tick box", () => {
    const f = new FormData();
    for (const [k, v] of [["project_id", "p1"], ["work_date", "2026-11-10"], ["hours", "2,5"], ["rate", "1,200"], ["note", " "]]) f.append(k, v);
    expect(timeBody(f)).toEqual({ project_id: "p1", work_date: "2026-11-10", hours: "2.5", billable: false, rate: "1200", note: null });
    f.append("billable", "on");
    expect(timeBody(f).billable).toBe(true);
  });
});

describe("milestoneTotal", () => {
  it("adds the milestones exactly, in cents", () => {
    expect(milestoneTotal([])).toBe("0.00");
    expect(milestoneTotal([{ amount: "1500.00" }, { amount: "0.10" }, { amount: "0.20" }])).toBe("1500.30");
    expect(milestoneTotal([{ amount: "9999999999999999.99" }, { amount: "0.01" }])).toBe("10000000000000000.00");
  });
});

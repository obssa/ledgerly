import { describe, expect, it } from "vitest";
import { parseLines } from "./invoice";
import { projectBody } from "./projects";

describe("projectBody", () => {
  it("sends the code only when the project is new, and empty fields as null", () => {
    const f = new FormData();
    for (const [k, v] of [["code", " P-7 "], ["name", "Bole road"], ["status", "active"], ["billing", "fixed_fee"], ["customer_id", ""], ["contract_value", "1,250,000.00"], ["budget_cost", ""]]) f.append(k, v);
    expect(projectBody(f, true)).toEqual({ code: "P-7", name: "Bole road", status: "active", billing: "fixed_fee", customer_id: null, starts_on: null, ends_on: null, notes: null,
      contract_value: "1250000.00", budget_income: null, budget_cost: null });
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

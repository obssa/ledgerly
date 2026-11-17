import { describe, expect, it } from "vitest";
import { groupGrantable, roleSummary } from "./team";

describe("groupGrantable", () => {
  it("groups by the area before the colon, in the order given", () => {
    expect(groupGrantable([
      { permission: "invoices.view", label: "Sales: see invoices" },
      { permission: "bills.view", label: "Purchases: see bills" },
      { permission: "invoices.approve", label: "Sales: post invoices" },
      { permission: "audit.view", label: "See the audit log" },
    ])).toEqual([
      { area: "Sales", items: [{ permission: "invoices.view", label: "See invoices" }, { permission: "invoices.approve", label: "Post invoices" }] },
      { area: "Purchases", items: [{ permission: "bills.view", label: "See bills" }] },
      { area: "Other", items: [{ permission: "audit.view", label: "See the audit log" }] },
    ]);
  });
});

describe("roleSummary", () => {
  it("names a role, and counts a custom role's own permissions", () => {
    expect(roleSummary("accountant", [])).toBe("accountant");
    expect(roleSummary("custom", ["company.view", "invoices.view"])).toBe("custom: 1 permission");
    expect(roleSummary("custom", ["company.view", "invoices.view", "reports.view"])).toBe("custom: 2 permissions");
  });
});

import { describe, expect, it } from "vitest";
import { actionLabel, changesOf } from "./audit";

describe("changesOf", () => {
  it("lists only the fields that changed", () => {
    expect(changesOf({ name: "Van", status: "active", cost: "60000.00" }, { name: "Isuzu van", status: "active", cost: "60000.00" }))
      .toEqual([{ key: "name", from: "Van", to: "Isuzu van" }]);
  });
  it("shows a new record by its after, and objects as JSON", () => {
    expect(changesOf(null, { code: "IT-001", options: { group: "item" } })).toEqual([
      { key: "code", from: "", to: "IT-001" }, { key: "options", from: "", to: '{"group":"item"}' },
    ]);
    expect(changesOf({ is_active: true }, { is_active: false })).toEqual([{ key: "is_active", from: "true", to: "false" }]);
  });
});

describe("actionLabel", () => {
  it("reads an action as words", () => {
    expect(actionLabel("journal_reversed")).toBe("Journal reversed");
  });
});

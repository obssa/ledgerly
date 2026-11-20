import { describe, expect, it } from "vitest";
import { IMPORT_KINDS } from "./imports";
import { MIGRATION_SOURCES, migrationSource } from "./migration";

describe("moving from another program (096)", () => {
  it("names only steps the import page has, chart first and balances last", () => {
    for (const source of Object.values(MIGRATION_SOURCES)) {
      for (const step of source.steps) expect(Object.keys(IMPORT_KINDS)).toContain(step.kind);
      expect(source.steps[0].kind).toBe("accounts");
      expect(source.steps.at(-1)?.kind).toBe("opening-balances");
    }
  });
  it("reads the source from the address", () => {
    expect(migrationSource("tally")).toBe("tally");
    expect(migrationSource("xero")).toBeNull();
    expect(migrationSource(undefined)).toBeNull();
  });
});

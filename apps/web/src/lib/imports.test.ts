import { describe, expect, it } from "vitest";
import { importKind, importSummary, type ImportResult } from "./imports";

const result = (over: Partial<ImportResult>): ImportResult => ({ kind: "customers", checked: true, rows: 3, added: 0, errors: [], columns: ["name"], ignored_columns: [], ...over });

describe("imports", () => {
  it("reads only a known kind", () => {
    expect(importKind("items")).toBe("items");
    expect(importKind("users")).toBe("customers");
  });
  it("names the chart and opening balances", () => {
    expect(importKind("accounts")).toBe("accounts");
    expect(importKind("opening-balances")).toBe("opening-balances");
    expect(importSummary(result({ kind: "accounts", checked: false, added: 2, skipped: ["1110"] }))).toBe("2 accounts added. 1 already in the chart, left as they are (1110).");
    expect(importSummary(result({ kind: "opening-balances", checked: false, added: 2 }))).toBe("Opening balances entered: 2 accounts.");
    expect(importSummary(result({ kind: "open-bills", checked: false, added: 4 }))).toBe("4 open bills brought in.");
  });
  it("says what happened", () => {
    expect(importSummary(result({}))).toBe("All 3 rows are fine. Import them to add the customers.");
    expect(importSummary(result({ checked: false, added: 3 }))).toBe("3 customers added.");
    expect(importSummary(result({ checked: false, errors: [{ row: 3, name: "Tomoca", messages: ["The email field must be a valid email address."] }] })))
      .toBe("1 of 3 rows need a fix; nothing was added. Correct the file and try again.");
  });
});

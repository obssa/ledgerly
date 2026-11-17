import { describe, expect, it } from "vitest";
import { safeCell, toCsv } from "./csv";

describe("safeCell", () => {
  it("keeps numbers and escapes what a spreadsheet would run", () => {
    expect(safeCell("-1250.50")).toBe("-1250.50");
    expect(safeCell(42)).toBe("42");
    expect(safeCell("=HYPERLINK(\"x\")")).toBe("'=HYPERLINK(\"x\")");
    expect(safeCell("+2")).toBe("+2".replace(/^/, "'"));
    expect(safeCell("@SUM(A1)")).toBe("'@SUM(A1)");
    expect(safeCell("- not a number")).toBe("'- not a number");
    expect(safeCell(null)).toBe("");
  });
});

describe("toCsv", () => {
  it("quotes commas, quotes and line breaks, and ends lines with CRLF", () => {
    expect(toCsv([["Account", "Amount"], ["Rent, office", "1500.00"], ['Say "hi"', "2"], ["two\nlines", null]]))
      .toBe('Account,Amount\r\n"Rent, office",1500.00\r\n"Say ""hi""",2\r\n"two\nlines",\r\n');
  });
});

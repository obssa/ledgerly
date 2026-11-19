import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
const { toXlsx } = await import("./xlsx");
const { readXlsx, rowsToCsv, serialDate, XlsxError } = await import("./xlsxRead");

describe("reading a workbook", () => {
  it("reads back the first sheet a workbook was written with, text and numbers", () => {
    const book = toXlsx("Journals", [["Date", "Account", "Debit", "Memo"], ["2026-11-12", "6800", "15000", "Audit fee, accrued"], ["2026-11-12", "2100", "", "A & B <ltd>"]]);
    expect(readXlsx(book)).toEqual([["Date", "Account", "Debit", "Memo"], ["2026-11-12", "6800", "15000", "Audit fee, accrued"], ["2026-11-12", "2100", "", "A & B <ltd>"]]);
  });

  it("turns rows into CSV the importers read, quoting what needs it", () => {
    expect(rowsToCsv([["Date", "Memo"], ["2026-11-12", 'Fee, "audit"']])).toBe('Date,Memo\n2026-11-12,"Fee, ""audit"""');
  });

  it("gives an Excel date serial as a date", () => {
    expect(serialDate(46338)).toBe("2026-11-12");
    expect(serialDate(1)).toBe("1899-12-31");
  });

  it("refuses what is not a workbook, or too large to read", () => {
    expect(() => readXlsx(new TextEncoder().encode("Date,Account\n2026-11-12,6800"))).toThrow(XlsxError);
    expect(() => readXlsx(new Uint8Array(6 * 1024 * 1024))).toThrow(XlsxError);
  });
});

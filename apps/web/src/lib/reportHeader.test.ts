import { describe, expect, it } from "vitest";
import { exportFileName, footerLine, footerRows, headerRows, preparedLabel, type ReportIdentity } from "./reportHeader";

const h: ReportIdentity = {
  company: "Abyssinia Coffee", legalName: "Abyssinia Coffee Export PLC", title: "Profit and loss", period: "1 July 2026 – 26 November 2026",
  filters: ["Location: Head office"], basis: "accrual", currency: "ETB", prepared: "Prepared Thursday, 26 November 2026, 10:15 by Obssa Test 1",
};

describe("the report header and footer", () => {
  it("heads a file with company, legal name, report, period and filters, then a blank row", () => {
    expect(headerRows(h)).toEqual([["Abyssinia Coffee"], ["Abyssinia Coffee Export PLC"], ["Profit and loss"], ["1 July 2026 – 26 November 2026"], ["Location: Head office"], []]);
    expect(headerRows({ ...h, legalName: "Abyssinia Coffee", filters: [] })).toEqual([["Abyssinia Coffee"], ["Profit and loss"], ["1 July 2026 – 26 November 2026"], []]);
  });

  it("ends it with the basis, the currency and when and by whom it was prepared", () => {
    expect(footerLine(h)).toBe("Accrual basis · Amounts in ETB · Prepared Thursday, 26 November 2026, 10:15 by Obssa Test 1");
    expect(footerLine({ ...h, basis: "cash", currency: null })).toBe("Cash basis · Prepared Thursday, 26 November 2026, 10:15 by Obssa Test 1");
    expect(footerRows(h)).toEqual([[], [footerLine(h)]]);
  });

  it("says when, in the company's time zone and calendar", () => {
    const at = new Date("2026-11-26T07:15:00Z");
    expect(preparedLabel(at, "Africa/Addis_Ababa", "gregorian", "Obssa Test 1")).toBe("Prepared Thursday, 26 November 2026, 10:15 by Obssa Test 1");
    expect(preparedLabel(at, "Africa/Addis_Ababa", "ethiopian")).toBe("Prepared Thursday, Hidar 17, 2019, 10:15");
    expect(preparedLabel(new Date("2026-11-26T22:30:00Z"), "Africa/Addis_Ababa", "gregorian")).toBe("Prepared Friday, 27 November 2026, 01:30");
  });

  it("names the file after what it is, with nothing a file system refuses", () => {
    expect(exportFileName(h, "xlsx")).toBe("Abyssinia Coffee – Profit and loss – 1 July 2026 – 26 November 2026.xlsx");
    expect(exportFileName({ ...h, company: 'A/B: "C"' }, "csv")).toBe("A B C – Profit and loss – 1 July 2026 – 26 November 2026.csv");
  });
});

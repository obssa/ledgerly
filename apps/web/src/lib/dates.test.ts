import { describe, expect, it } from "vitest";
import { formatDate, formatDateTime, parseDate, pickDateFormat, todayIn, type DatePrefs } from "./dates";

const dmy: DatePrefs = { calendar: "gregorian", dateFormat: "DD/MM/YYYY" };
const mdy: DatePrefs = { calendar: "gregorian", dateFormat: "MM/DD/YYYY" };
const ymd: DatePrefs = { calendar: "gregorian", dateFormat: "YYYY-MM-DD" };
const eth: DatePrefs = { calendar: "ethiopian", dateFormat: "DD/MM/YYYY" };

describe("formatDate: the company's date format", () => {
  it("writes day first by default, month first or ISO when chosen", () => {
    expect(formatDate("2026-11-27")).toBe("27/11/2026");
    expect(formatDate("2026-11-27", dmy)).toBe("27/11/2026");
    expect(formatDate("2026-03-04", mdy)).toBe("03/04/2026");
    expect(formatDate("2026-11-27", ymd)).toBe("2026-11-27");
    expect(formatDate("2026-11-27T09:30:00Z", dmy)).toBe("27/11/2026");
  });

  it("writes Ethiopian-calendar dates as their own numbers", () => {
    expect(formatDate("2026-11-26", eth)).toBe("17/03/2019");        // Hidar 17, 2019
    expect(formatDate("2026-09-10", eth)).toBe("05/13/2018");        // Pagume 5, 2018
    expect(formatDate("2026-11-26", { calendar: "ethiopian", dateFormat: "MM/DD/YYYY" })).toBe("03/17/2019");
  });

  it("leaves empty and non-dates alone", () => {
    expect(formatDate(null)).toBe("—");
    expect(formatDate("", dmy, "")).toBe("");
    expect(formatDate("Opening", dmy)).toBe("Opening");
    expect(pickDateFormat("MM/DD/YYYY")).toBe("MM/DD/YYYY");
    expect(pickDateFormat("dd.mm.yy")).toBe("DD/MM/YYYY");
  });

  it("writes a moment in the company's time zone, 24-hour", () => {
    expect(formatDateTime("2026-11-16T22:30:00Z", dmy, "Africa/Addis_Ababa")).toBe("17/11/2026 01:30");
    expect(formatDateTime("2026-11-16T22:30:00Z", mdy, "UTC")).toBe("11/16/2026 22:30");
  });
});

describe("parseDate: what is typed in a date field", () => {
  it("reads the company's order strictly, with / - or .", () => {
    expect(parseDate("27/11/2026", dmy)).toBe("2026-11-27");
    expect(parseDate("3.4.2026", dmy)).toBe("2026-04-03");
    expect(parseDate("03/04/2026", mdy)).toBe("2026-03-04");
    expect(parseDate("27/11/2026", mdy)).toBeNull();
    expect(parseDate("27-11-2026", ymd)).toBe("2026-11-27");
  });

  it("always reads ISO, and refuses days that do not exist", () => {
    expect(parseDate("2026-11-27", dmy)).toBe("2026-11-27");
    expect(parseDate("31/02/2026", dmy)).toBeNull();
    expect(parseDate("29/02/2028", dmy)).toBe("2028-02-29");
    expect(parseDate("29/02/2027", dmy)).toBeNull();
    expect(parseDate("tomorrow", dmy)).toBeNull();
    expect(parseDate("", dmy)).toBeNull();
  });

  it("reads Ethiopian numbers for an Ethiopian-calendar company", () => {
    expect(parseDate("17/03/2019", eth)).toBe("2026-11-26");
    expect(parseDate("05/13/2018", eth)).toBe("2026-09-10");
    expect(parseDate("07/13/2018", eth)).toBeNull();                 // Pagume has 5 or 6 days
    expect(parseDate("2026-11-26", eth)).toBe("2026-11-26");         // ISO stays Gregorian
  });

  it("round-trips every format", () => {
    for (const p of [dmy, mdy, ymd, eth]) for (const d of ["2026-01-01", "2026-12-31", "2028-02-29", "2027-09-11"]) expect(parseDate(formatDate(d, p), p)).toBe(d);
  });
});

describe("todayIn", () => {
  const lateUtc = new Date("2026-11-16T22:30:00Z");   // 01:30 on 17 Nov in Addis Ababa

  it("is the date in the company's time zone, not the UTC date", () => {
    expect(todayIn("Africa/Addis_Ababa", lateUtc)).toBe("2026-11-17");
    expect(todayIn("UTC", lateUtc)).toBe("2026-11-16");
    expect(todayIn("Africa/Lagos", lateUtc)).toBe("2026-11-16");   // UTC+1: 23:30
  });

  it("falls back to Addis Ababa for an empty or unknown zone", () => {
    expect(todayIn(null, lateUtc)).toBe("2026-11-17");
    expect(todayIn("", lateUtc)).toBe("2026-11-17");
    expect(todayIn("Not/AZone", lateUtc)).toBe("2026-11-17");
  });
});

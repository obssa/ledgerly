import { describe, expect, it } from "vitest";
import { describeTerm, dueDate } from "./terms";

/** The same dates and answers as apps/api/tests/Unit/PaymentTermsTest.php: the web app and the server must agree. */
const days = (n: number) => ({ kind: "days" as const, days: n });
const dom = (day: number, within = 0) => ({ kind: "day_of_month" as const, day_of_month: day, next_month_within: within });

describe("dueDate", () => {
  it("adds a days term's days", () => {
    expect(dueDate("2026-11-02", days(0))).toBe("2026-11-02");
    expect(dueDate("2026-11-02", days(30))).toBe("2026-12-02");
    expect(dueDate("2026-11-02", days(60), "ethiopian")).toBe("2027-01-01");
  });

  it("takes a day of the month in the Gregorian calendar", () => {
    expect(dueDate("2026-02-10", dom(31))).toBe("2026-02-28");
    expect(dueDate("2028-02-10", dom(31))).toBe("2028-02-29");
    expect(dueDate("2026-11-30", dom(31))).toBe("2026-11-30");
    expect(dueDate("2026-11-05", dom(10))).toBe("2026-11-10");
    expect(dueDate("2026-11-12", dom(10))).toBe("2026-12-10");
    expect(dueDate("2026-12-15", dom(10))).toBe("2027-01-10");
    expect(dueDate("2026-11-06", dom(10, 5))).toBe("2026-12-10");
    expect(dueDate("2026-11-05", dom(10, 5))).toBe("2026-11-10");
    expect(dueDate("2026-02-03", dom(30))).toBe("2026-02-28");
  });

  it("takes a day of the month in the Ethiopian calendar, Pagume counted with Nehase", () => {
    expect(dueDate("2026-11-02", dom(31), "ethiopian")).toBe("2026-11-09");
    expect(dueDate("2026-08-20", dom(31), "ethiopian")).toBe("2026-09-10");
    expect(dueDate("2026-08-20", dom(30), "ethiopian")).toBe("2026-09-05");
    expect(dueDate("2026-09-07", dom(10), "ethiopian")).toBe("2026-09-20");
    expect(dueDate("2026-11-02", dom(10), "ethiopian")).toBe("2026-11-19");
  });

  it("gives nothing for a date not yet whole", () => {
    expect(dueDate("2026-11", days(30))).toBe("");
  });
});

describe("describeTerm", () => {
  it("says what a term does", () => {
    expect(describeTerm(days(0))).toBe("Due the day it is dated");
    expect(describeTerm(days(30))).toBe("30 days after the date");
    expect(describeTerm(dom(31))).toBe("Due the last day of the month");
    expect(describeTerm(dom(10, 5))).toBe("Due day 10 of the month; the next month when dated within 5 days of it");
  });
});

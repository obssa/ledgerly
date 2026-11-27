import { describe, expect, it } from "vitest";
import { appliedTo, creditStatus, daysBetween, methodLabel, paymentStatus } from "./docStatus";

const doc = (status: string, due_date: string | null, total: string, amount_due: string) => ({ status, due_date, total, amount_due });
const today = "2026-11-27";

describe("paymentStatus, in QuickBooks' words", () => {
  it("counts the days to and past the due date", () => {
    expect(daysBetween("2026-11-27", "2026-12-27")).toBe(30);
    expect(daysBetween("2026-03-01", "2026-02-28")).toBe(-1);
    expect(daysBetween("2028-02-28", "2028-03-01")).toBe(2);
    expect(paymentStatus(doc("posted", "2026-12-27", "115.00", "115.00"), today)).toEqual({ label: "Due in 30 days", tone: "brand" });
    expect(paymentStatus(doc("posted", "2026-11-28", "115.00", "115.00"), today).label).toBe("Due in 1 day");
    expect(paymentStatus(doc("posted", today, "115.00", "115.00"), today)).toEqual({ label: "Due today", tone: "warn" });
    expect(paymentStatus(doc("posted", "2026-11-26", "115.00", "115.00"), today)).toEqual({ label: "Overdue 1 day", tone: "bad" });
    expect(paymentStatus(doc("posted", "2026-11-15", "115.00", "115.00"), today).label).toBe("Overdue 12 days");
  });

  it("says when it is partly or fully paid", () => {
    expect(paymentStatus(doc("part_paid", "2026-12-27", "115.00", "15.00"), today)).toEqual({ label: "Partially paid", tone: "warn" });
    expect(paymentStatus(doc("part_paid", "2026-11-20", "115.00", "15.00"), today).label).toBe("Overdue 7 days · partially paid");
    expect(paymentStatus(doc("paid", "2026-11-20", "115.00", "0.00"), today)).toEqual({ label: "Paid", tone: "good" });
    expect(paymentStatus(doc("posted", null, "115.00", "115.00"), today)).toEqual({ label: "Open", tone: "brand" });
  });

  it("keeps draft, approval and void as they are", () => {
    expect(paymentStatus(doc("draft", "2026-01-01", "115.00", "115.00"), today).label).toBe("Draft");
    expect(paymentStatus(doc("awaiting_approval", null, "1.00", "1.00"), today).label).toBe("Awaiting approval");
    expect(paymentStatus(doc("void", "2026-01-01", "115.00", "0.00"), today).label).toBe("Void");
  });
});

describe("creditStatus", () => {
  it("says how much of a credit has been used", () => {
    expect(creditStatus(doc("posted", null, "50.00", "50.00")).label).toBe("Unapplied");
    expect(creditStatus(doc("part_paid", null, "50.00", "20.00")).label).toBe("Partially applied");
    expect(creditStatus(doc("paid", null, "50.00", "0.00")).label).toBe("Applied");
    expect(creditStatus(doc("draft", null, "50.00", "50.00")).label).toBe("Draft");
  });
});

describe("labels", () => {
  it("names payment methods and what a payment was applied to", () => {
    expect(methodLabel("bank_transfer")).toBe("Bank transfer");
    expect(methodLabel("mobile_money")).toBe("Mobile money");
    expect(methodLabel("direct_debit_x")).toBe("Direct debit x");
    expect(appliedTo(0, "invoice")).toBe("—");
    expect(appliedTo(1, "invoice")).toBe("1 invoice");
    expect(appliedTo(3, "bill")).toBe("3 bills");
  });
});

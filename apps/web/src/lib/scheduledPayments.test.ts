import { describe, expect, it } from "vitest";
import { dueLabel, scheduleBills } from "./scheduledPayments";

const A = "0190f0a0-0000-7000-8000-00000000000a";
const B = "0190f0a0-0000-7000-8000-00000000000b";

describe("the bills of a scheduled payment", () => {
  it("takes the ticked bills, with the amount typed or all that is due", () => {
    const f = new FormData();
    f.set(`bill:${A}`, "on");
    f.set(`amount:${A}`, "1,250.50");
    f.set(`amount:${B}`, "99");   // not ticked
    f.set(`bill:${B}x`, "on");     // not an id
    expect(scheduleBills(f)).toEqual([{ bill_id: A, amount: "1250.50" }]);
    f.set(`bill:${B}`, "on");
    f.set(`amount:${B}`, " ");
    expect(scheduleBills(f)).toEqual([{ bill_id: A, amount: "1250.50" }, { bill_id: B, amount: null }]);
  });
});

describe("how near the day is", () => {
  it("says today, late or how many days ahead", () => {
    expect(dueLabel("2026-11-24", "2026-11-24")).toEqual({ text: "Due today", tone: "warn" });
    expect(dueLabel("2026-11-22", "2026-11-24")).toEqual({ text: "2 days late", tone: "bad" });
    expect(dueLabel("2026-11-23", "2026-11-24").text).toBe("1 day late");
    expect(dueLabel("2026-11-25", "2026-11-24").text).toBe("Tomorrow");
    expect(dueLabel("2026-12-01", "2026-11-24").text).toBe("In 7 days");
  });
});

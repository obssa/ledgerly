import { describe, expect, it } from "vitest";
import { subscriptionNotice, type Subscription } from "./subscription";

const sub = (over: Partial<Subscription>): Subscription => ({
  plan: "starter", plan_name: "Starter", status: "trial", until: "2026-12-17", days_left: 20, read_only: false, demo: false, enforced: true,
  instructions: null, contact: "", can: { manage: true }, ...over,
});

describe("subscriptionNotice", () => {
  it("says nothing for a healthy subscription, a long trial or the demo", () => {
    expect(subscriptionNotice(sub({ status: "active" }))).toBeNull();
    expect(subscriptionNotice(sub({}))).toBeNull();
    expect(subscriptionNotice(sub({ status: "suspended", read_only: true, demo: true }))).toBeNull();
    expect(subscriptionNotice(null)).toBeNull();
  });
  it("warns of a trial ending within a week, only once billing is enforced", () => {
    expect(subscriptionNotice(sub({ days_left: 3 }))?.text).toContain("ends in 3 days");
    expect(subscriptionNotice(sub({ days_left: 1 }))?.text).toContain("ends tomorrow");
    expect(subscriptionNotice(sub({ days_left: 3, enforced: false }))).toBeNull();
  });
  it("tells of an overdue payment and of read-only books, with where to pay for those who can", () => {
    expect(subscriptionNotice(sub({ status: "past_due", until: "2026-11-30" }))).toEqual({ tone: "warn", text: expect.stringContaining("paid until 2026-11-30") });
    const ro = subscriptionNotice(sub({ status: "suspended", read_only: true }));
    expect(ro?.tone).toBe("bad");
    expect(ro?.text).toContain("Plan and billing");
    expect(subscriptionNotice(sub({ status: "suspended", read_only: true, can: { manage: false } }))?.text).not.toContain("Plan and billing");
  });
});

import { describe, expect, it } from "vitest";
import { sessionTime, sortSessions, type SignedInSession } from "./sessions";

const s = (id: string, current: boolean, last: string | null): SignedInSession => ({
  id, device: "Chrome on Windows", address: null, signed_in_at: null, last_active_at: last, expires_at: null, current,
});

describe("sessionTime", () => {
  it("shows Addis Ababa time", () => {
    expect(sessionTime("2026-11-17T06:05:00+00:00")).toBe("17 Nov 2026, 09:05");
  });
  it("shows nothing for a missing or broken time", () => {
    expect(sessionTime(null)).toBe("");
    expect(sessionTime("not a time")).toBe("");
  });
});

describe("sortSessions", () => {
  it("puts this device first, then the most recently active", () => {
    const sorted = sortSessions([s("1", false, "2026-11-10T08:00:00+00:00"), s("2", false, "2026-11-17T08:00:00+00:00"), s("3", true, "2026-11-01T08:00:00+00:00")]);
    expect(sorted.map((x) => x.id)).toEqual(["3", "2", "1"]);
  });
});

import { describe, expect, it } from "vitest";
import { todayIn } from "./dates";

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

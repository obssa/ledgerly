import { describe, expect, it } from "vitest";
import { emptyPage, listQuery, paged } from "./lists";

describe("a list's period and pages", () => {
  it("sends the period's ends, none for an open end, and a cursor only when it is one", () => {
    expect(listQuery({}, { preset: "all", from: null, to: null })).toEqual({ from: undefined, to: undefined });
    expect(listQuery({ cursor: "eyJpZCI6MX0" }, { preset: "this_month", from: "2026-11-01", to: "2026-11-30" }))
      .toEqual({ from: "2026-11-01", to: "2026-11-30", cursor: "eyJpZCI6MX0" });
    expect(listQuery({ cursor: "a b<script>" }, { preset: "since_30_days_ago", from: "2026-10-27", to: null })).toEqual({ from: "2026-10-27", to: undefined });
  });

  it("keeps the rows as a list, with where the pages around it start", () => {
    const page = paged({ data: [1, 2, 3], next_cursor: "n", prev_cursor: null });
    expect([...page]).toEqual([1, 2, 3]);
    expect(page.map((x) => x * 2)).toEqual([2, 4, 6]);
    expect([page.next, page.prev]).toEqual(["n", null]);
    expect([emptyPage().length, emptyPage().next]).toEqual([0, null]);
  });
});

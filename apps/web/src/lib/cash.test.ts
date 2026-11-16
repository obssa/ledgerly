import { describe, expect, it } from "vitest";
import { countTotal, denominationsFrom } from "./cash";

describe("countTotal", () => {
  it("adds up the notes and coins exactly", () => {
    expect(countTotal({ "200": 9, "10": 4 })).toBe("1840.00");
    expect(countTotal({ "0.25": 3, "0.05": 1, "1": "2" })).toBe("2.80");
    expect(countTotal({ "100": 0, "50": -1, "5": "" })).toBe("0.00");
  });
});

describe("denominationsFrom", () => {
  it("reads the pieces fields and leaves out the empty ones", () => {
    const f = new FormData();
    f.append("pieces:200", "9");
    f.append("pieces:10", " 4 ");
    f.append("pieces:5", "");
    f.append("pieces:1", "0");
    f.append("counted_by_name", "Meron");
    expect(denominationsFrom(f)).toEqual({ "200": 9, "10": 4 });
    const bad = new FormData();
    bad.append("pieces:200", "1.5");
    expect(() => denominationsFrom(bad)).toThrow();
  });
});

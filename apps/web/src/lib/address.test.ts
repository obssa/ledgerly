import { describe, expect, it } from "vitest";
import { addressText } from "./address";

describe("addressText", () => {
  it("prints an address a part to a line, leaving out what is empty", () => {
    expect(addressText({ line1: "Bole Road", city: "Addis Ababa", region: "Addis Ababa", country: "ET" })).toBe("Bole Road\nAddis Ababa, Addis Ababa\nET");
    expect(addressText({ line1: " ", postal_code: "1000" })).toBe("1000");
    expect(addressText(null)).toBe("");
  });
});

import { describe, expect, it } from "vitest";
import { findBarcode } from "./barcodes";

const items = [
  { id: "bag", barcode: "4006381333931", units: [{ id: "carton", barcode: "14006381333938" }, { id: "sack", barcode: null }] },
  { id: "cups", barcode: null, units: [] },
];

describe("barcodes", () => {
  it("finds the item in its own unit, or the unit that carries the barcode", () => {
    expect(findBarcode(items, "4006381333931")).toEqual({ itemId: "bag", unitId: null });
    expect(findBarcode(items, " 14006381333938 ")).toEqual({ itemId: "bag", unitId: "carton" });
  });

  it("finds nothing for an unknown or empty code", () => {
    expect(findBarcode(items, "999")).toBeNull();
    expect(findBarcode(items, "  ")).toBeNull();
  });
});

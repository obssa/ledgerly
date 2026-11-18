import { describe, expect, it } from "vitest";
import { deliveries } from "./orders";

const a = "0a1b2c3d-0000-4000-8000-000000000001";
const b = "0a1b2c3d-0000-4000-8000-000000000002";

describe("deliveries", () => {
  it("reads one quantity per order line, an empty field as 0", () => {
    const form = new FormData();
    form.set("id", "x");
    form.set(`deliver_${a}`, " 1,200.50 ");
    form.set(`deliver_${b}`, "");
    expect(deliveries(form)).toEqual([{ line_id: a, quantity: "1200.50" }, { line_id: b, quantity: "0" }]);
  });

  it("is undefined without any line, so the API delivers all that is left", () => {
    const form = new FormData();
    form.set("deliver_not-a-line", "3");
    expect(deliveries(form)).toBeUndefined();
  });
});

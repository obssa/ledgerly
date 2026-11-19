import { describe, expect, it } from "vitest";
import { batchBody } from "./batchPayments";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("batch payments", () => {
  const suppliers = { b1: "s1", b2: "s1", b3: "s2" };

  it("sends the bills ticked, at the amount typed or all they owe", () => {
    expect(batchBody(form([["bill.b1", "on"], ["amount.b1", ""], ["bill.b3", "on"], ["amount.b3", "1,200.50"], ["amount.b2", "99"]]), suppliers).bills)
      .toEqual([{ bill_id: "b1" }, { bill_id: "b3", amount: "1200.50" }]);
  });

  it("sends tax withheld only for a supplier paid in the run, with its receipt", () => {
    const body = batchBody(form([["bill.b1", "on"], ["wh.s1", "600"], ["whcert.s1", " WHT-1 "], ["wh.s2", "50"], ["whcert.s2", "WHT-2"]]), suppliers);
    expect(body.withholding).toEqual([{ contact_id: "s1", amount: "600", certificate: "WHT-1" }]);
  });

  it("ignores a bill that is not on the list", () => {
    expect(batchBody(form([["bill.zz", "on"]]), suppliers).bills).toEqual([]);
  });
});

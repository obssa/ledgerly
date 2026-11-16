import { describe, expect, it } from "vitest";
import { journalAccounts, journalBody, journalTotals, reclassifyBody, recurringBody } from "./journal";

describe("journalTotals", () => {
  it("adds debits and credits exactly and says when the entry balances", () => {
    expect(journalTotals([{ account_id: "a", debit: "1,250.50", credit: "", description: "" }, { account_id: "b", debit: "", credit: "1250.5", description: "" }]))
      .toEqual({ debit: "1250.50", credit: "1250.50", difference: "0.00", balanced: true });
    expect(journalTotals([{ account_id: "a", debit: "10", credit: "", description: "" }, { account_id: "b", debit: "", credit: "9.99", description: "" }]))
      .toMatchObject({ difference: "0.01", balanced: false });
    expect(journalTotals([]).balanced).toBe(false);
  });
});

describe("journalBody", () => {
  it("sends the lines with an account and an amount, without separators", () => {
    const f = new FormData();
    f.append("date", "2026-11-10");
    f.append("memo", " November rent accrued ");
    f.append("lines", JSON.stringify([
      { account_id: "rent", debit: "1,500", credit: "", description: " Rent ", location_id: "hq" },
      { account_id: "accrued", debit: "", credit: "1,500.00", description: "" },
      { account_id: "", debit: "5", credit: "", description: "no account" },
      { account_id: "spare", debit: "", credit: "", description: "no amount" },
    ]));
    expect(journalBody(f)).toEqual({ date: "2026-11-10", memo: "November rent accrued", lines: [
      { account_id: "rent", debit: "1500", description: "Rent", location_id: "hq" },
      { account_id: "accrued", credit: "1500.00" },
    ] });
    const broken = new FormData();
    broken.append("lines", "{not json");
    expect(journalBody(broken).lines).toEqual([]);
  });
});

describe("journalAccounts", () => {
  it("leaves out archived, foreign-currency and document-kept accounts", () => {
    const a = (over: object) => ({ is_active: true, subtype: null, system_key: null, currency: null, code: "0", ...over });
    expect(journalAccounts([
      a({ code: "6100" }), a({ code: "1200", subtype: "receivable", system_key: "accounts_receivable" }), a({ code: "1300", system_key: "inventory" }),
      a({ code: "1101", currency: "USD" }), a({ code: "6200", is_active: false }), a({ code: "1100", currency: "ETB" }), a({ code: "3100", system_key: "retained_earnings" }),
    ], "ETB").map((x) => x.code)).toEqual(["1100", "6100"]);
  });
});

describe("reverse_on and recurringBody", () => {
  const lines = JSON.stringify([{ account_id: "rent", debit: "300", credit: "", description: "" }, { account_id: "equity", debit: "", credit: "300", description: "" }]);
  it("sends when a journal reverses only when one is given", () => {
    const f = new FormData();
    f.append("date", "2026-11-30");
    f.append("memo", "Audit fee accrued");
    f.append("lines", lines);
    expect(journalBody(f)).not.toHaveProperty("reverse_on");
    f.append("reverse_on", "2026-12-01");
    expect(journalBody(f).reverse_on).toBe("2026-12-01");
  });
  it("sends a template's name and schedule with its lines, and no end as null", () => {
    const f = new FormData();
    for (const [k, v] of [["name", " Office rent "], ["memo", "Rent for the month"], ["frequency", "monthly"], ["next_on", "2026-12-31"], ["ends_on", ""], ["lines", lines]]) f.append(k, v);
    expect(recurringBody(f)).toEqual({ name: "Office rent", memo: "Rent for the month", frequency: "monthly", next_on: "2026-12-31", ends_on: null,
      lines: [{ account_id: "rent", debit: "300" }, { account_id: "equity", credit: "300" }] });
  });
});

describe("reclassifyBody", () => {
  it("sends each ticked line once, and the memo only when given", () => {
    const a = "01a1e5d0-ded7-7169-ab35-a3df7a7508e8";
    const b = "01a1e5d0-ded7-7169-ab35-a3df6a863dfa";
    const f = new FormData();
    for (const [k, v] of [["line_ids", a], ["line_ids", b], ["line_ids", a], ["line_ids", "not-an-id"], ["to_account_id", "acc"], ["date", "2026-11-12"], ["memo", " "]]) f.append(k, v);
    expect(reclassifyBody(f)).toEqual({ line_ids: [a, b], to_account_id: "acc", date: "2026-11-12" });
    f.set("memo", "Repairs, not rent");
    expect(reclassifyBody(f).memo).toBe("Repairs, not rent");
  });
});

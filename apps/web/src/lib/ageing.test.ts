import { describe, expect, it } from "vitest";
import { AGE_COLUMNS, AGE_DEFAULTS, AGE_FOREIGN, ageRows, bucketLabel, groupAgeRows, pickPeriods, sumOf } from "./ageing";
import { chooseColumns, withoutUnusedForeign } from "./columns";
import { GL_COLUMNS, GL_DEFAULTS, statementRow, transactionHref } from "./ledgerColumns";
import type { ArAgeing } from "./api";

const doc = (o: Partial<NonNullable<ArAgeing["documents"]>[number]>) => ({
  id: "d1", contact_id: "c1", number: "INV-1", reference: null, issue_date: "2026-11-02", due_date: null, days_late: 28, bucket: "days_1_30",
  currency: "ETB", total: "1050.00", amount_due: "1000.00", amount: "1000.00", type: "invoice", base_total: "1050.00", exchange_rate: "1.000000", ...o,
});
const ageing: ArAgeing = {
  as_of: "2026-11-30", currency: "ETB", buckets: ["current", "days_1_30", "days_31_60", "days_61_90", "over_90"],
  rows: [
    { contact_id: "c1", name: "Abebe", current: "0.00", days_1_30: "1000.00", days_31_60: "0.00", days_61_90: "0.00", over_90: "0.00", credits: "-300.00", total: "700.00" },
    { contact_id: "c2", name: "Sara", current: "0.00", days_1_30: "0.00", days_31_60: "0.00", days_61_90: "0.00", over_90: "460.00", credits: "0.00", total: "460.00" },
  ],
  totals: { current: "0.00", days_1_30: "1000.00", days_31_60: "0.00", days_61_90: "0.00", over_90: "460.00", credits: "-300.00", total: "1160.00" },
  documents: [doc({}), doc({ id: "d2", contact_id: "c2", number: "INV-2", days_late: 120, bucket: "over_90", currency: "USD", total: "8.00", amount_due: "8.00", amount: "460.00", base_total: "460.00", exchange_rate: "57.500000" })],
};

describe("the ageing periods", () => {
  it("are QuickBooks' days per period and number of periods, in range or the standard 30 and 4", () => {
    expect(pickPeriods({})).toEqual({ interval: 30, periods: 4 });
    expect(pickPeriods({ interval: "15", periods: "6" })).toEqual({ interval: 15, periods: 6 });
    expect(pickPeriods({ interval: "0", periods: "13" })).toEqual({ interval: 30, periods: 4 });
    expect(pickPeriods({ interval: "1;DROP", periods: ["5"] })).toEqual({ interval: 30, periods: 4 });
  });

  it("are named as on the report", () => {
    expect(["current", "days_1_30", "days_16_30", "over_90", "odd"].map(bucketLabel)).toEqual(["Current", "1–30 days", "16–30 days", "Over 90 days", "odd"]);
  });
});

describe("the ageing detail", () => {
  it("has each document's fields, with foreign amounts only for another currency", () => {
    const [etb, usd] = ageRows(ageing);
    expect(etb).toMatchObject({ date: "2026-11-02", type: "invoice", name: "Abebe", days_past_due: 28, amount: "1050.00", open_balance: "1000.00", currency: null, foreign_amount: null });
    expect(usd).toMatchObject({ name: "Sara", currency: "USD", foreign_amount: "8.00", foreign_open_balance: "8.00", exchange_rate: "57.500000", open_balance: "460.00" });
  });

  it("groups by ageing period, then the credit not yet used, or by party with what each owes", () => {
    const rows = ageRows(ageing);
    const byAge = groupAgeRows(ageing, rows, "age", "Credit not used");
    expect(byAge.map((g) => [g.label, g.rows.length, g.total])).toEqual([["1–30 days", 1, "1000.00"], ["Over 90 days", 1, "460.00"], ["Credit not used", 1, "-300.00"]]);
    const byName = groupAgeRows(ageing, rows, "name", "Credit not used");
    expect(byName.map((g) => [g.label, g.credits, g.total])).toEqual([["Abebe", "-300.00", "700.00"], ["Sara", null, "460.00"]]);
  });

  it("totals foreign amounts only when the rows share a currency", () => {
    const rows = ageRows(ageing);
    expect(sumOf(rows, "open_balance")).toBe("1460.00");
    expect(sumOf(rows, "foreign_open_balance")).toBeNull();
    expect(sumOf(rows.slice(1), "foreign_open_balance")).toBe("8.00");
  });

  it("shows the foreign columns by default only when a document is in another currency", () => {
    const cols = chooseColumns({}, AGE_COLUMNS, AGE_DEFAULTS);
    expect(withoutUnusedForeign(cols, ageRows(ageing).slice(0, 1), {}, AGE_FOREIGN)).not.toContain("currency");
    expect(withoutUnusedForeign(cols, ageRows(ageing), {}, AGE_FOREIGN)).toContain("currency");
    // Columns the reader chose stay as chosen.
    expect(withoutUnusedForeign(["date", "currency"], [], { cols: "date,currency" }, AGE_FOREIGN)).toEqual(["date", "currency"]);
  });
});

describe("the ledger and statement columns", () => {
  it("default to QuickBooks' general ledger columns and drop unknown ones", () => {
    expect(chooseColumns({}, GL_COLUMNS, GL_DEFAULTS).slice(0, 9)).toEqual(["date", "type", "number", "name", "memo", "split", "debit", "credit", "balance"]);
    expect(chooseColumns({ cols: "split,password,date" }, GL_COLUMNS, GL_DEFAULTS)).toEqual(["split", "date"]);
  });

  it("read a supplier's bills as charges and payments as payments, the foreign amount with them", () => {
    const line = { date: "2026-11-02", entry: 7, source_type: "bill", memo: null, reference: "BILL-1", document_id: "b1", debit: "0.00", credit: "500.00", balance: "500.00",
      type: "bill", foreign_amount: "-10.00", currency: "USD" };
    expect(statementRow(line, 0, true)).toMatchObject({ charge: "500.00", payment: "0.00", foreign_amount: "10.00", number: "BILL-1", entry: "#7" });
    expect(statementRow({ ...line, debit: "500.00", credit: "0.00", foreign_amount: "10.00", type: "invoice" }, 0, false)).toMatchObject({ charge: "500.00", foreign_amount: "10.00" });
  });

  it("open each transaction where it lives", () => {
    expect(transactionHref({ type: "invoice", document_id: "i 1" })).toBe("/invoices/i%201");
    expect(transactionHref({ type: "payment_made", payment_id: "p1" })).toBe("/payments-made/p1");
    expect(transactionHref({ type: "manual", entry_id: "e1" })).toBe("/journal/e1");
    expect(transactionHref({ type: "vendor_credit", document_id: "v1" })).toBeNull();
  });
});

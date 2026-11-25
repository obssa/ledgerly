import { describe, expect, it } from "vitest";
import { customQuery, groupRows, moveColumn, pickColumns, pickGroup, pickSort, sortRows, totals, typeLabel, TX_REPORTS, type TxRow } from "./transactionColumns";

const row = (o: Partial<TxRow>): TxRow => ({
  id: "x", date: "2026-11-01", type: "invoice", number: null, name: null, tin: null, memo: null, due_date: null, days_past_due: null, status: null,
  amount: null, open_balance: null, tax: null, amount_before_tax: null, foreign_amount: null, foreign_open_balance: null, currency: null,
  exchange_rate: null, location: null, account: null, method: null, applied_to: null, created_by: null, created_at: null, posted_by: null, ...o,
});

describe("Customize, Columns", () => {
  const defaults = TX_REPORTS["open-invoices"].columns;

  it("shows the report's own columns until the reader chooses", () => {
    expect(pickColumns({}, defaults)).toEqual([...defaults]);
    expect(pickColumns({ cols: "name,amount,bogus,date" }, defaults)).toEqual(["name", "amount", "date"]);
  });

  it("keeps the reader's order when columns are ticked or unticked in Customize", () => {
    // Showing name, amount, date; Customize sends the ticked ones (catalogue order): date, name, tax.
    expect(pickColumns({ cols: "name,amount,date", customize: "1", col: ["date", "name", "tax"] }, defaults)).toEqual(["name", "date", "tax"]);
    expect(pickColumns({ cols: "name", customize: "1", col: [] }, defaults)).toEqual([...defaults]);
  });

  it("moves a column left or right", () => {
    expect(moveColumn(["date", "name", "amount"], "amount", -1)).toEqual(["date", "amount", "name"]);
    expect(moveColumn(["date", "name"], "date", -1)).toEqual(["date", "name"]);
  });

  it("sorts amounts as amounts, words as words, blanks last, either way", () => {
    const rows = [row({ id: "a", amount: "9.50", name: "Bole" }), row({ id: "b", amount: "100.00", name: "Abebe" }), row({ id: "c", amount: null, name: "Chala" })];
    expect(sortRows(rows, "amount", "asc").map((r) => r.id)).toEqual(["a", "b", "c"]);
    expect(sortRows(rows, "amount", "desc").map((r) => r.id)).toEqual(["b", "a", "c"]);
    expect(sortRows(rows, "name", "asc").map((r) => r.id)).toEqual(["b", "a", "c"]);
    expect(pickSort({ sort: "amount", dir: "desc" }, ["date", "amount"])).toEqual({ sort: "amount", dir: "desc" });
    expect(pickSort({ sort: "1; drop", dir: "sideways" }, ["date"])).toEqual({ sort: "date", dir: "asc" });
  });

  it("groups with subtotals; foreign totals only within one currency", () => {
    const rows = [row({ name: "Bole", amount: "10.00", foreign_amount: "1.00", currency: "USD" }), row({ name: "Abebe", amount: "5.25", foreign_amount: "2.00", currency: "EUR" }),
      row({ name: "Bole", amount: "1.75", foreign_amount: "3.00", currency: "USD" })];
    const groups = groupRows(rows, "name");
    expect(groups.map((g) => [g.label, g.rows.length])).toEqual([["Abebe", 1], ["Bole", 2]]);
    expect(totals(groups[1].rows, ["amount", "foreign_amount"])).toEqual({ amount: "11.75", foreign_amount: "4.00" });
    expect(totals(rows, ["amount", "foreign_amount"])).toEqual({ amount: "17.00" });
    expect(groupRows(rows, "none")).toHaveLength(1);
    expect(groupRows([row({ date: "2026-11-03" })], "month")[0].label).toBe("November 2026");
    expect(pickGroup({ group: "evil" }, "name")).toBe("name");
  });

  it("names types as people say them, and writes the choice back as URL fields", () => {
    expect(typeLabel("vendor_credit")).toBe("Supplier credit");
    expect(typeLabel("asset_revaluation")).toBe("Asset revaluation");
    expect(customQuery(["date", "amount"], { sort: "amount", dir: "desc" }, "none")).toEqual({ cols: "date,amount", sort: "amount", dir: "desc", group: "none" });
  });
});

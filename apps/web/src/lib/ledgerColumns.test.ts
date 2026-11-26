import { describe, expect, it } from "vitest";
import { chooseColumns } from "./columns";
import { BIN_COLUMNS, BIN_DEFAULTS, JOURNAL_COLUMNS, JOURNAL_DEFAULTS, binRow, journalEntries } from "./ledgerColumns";
import type { JournalReport } from "./api";

const line = (o: Partial<JournalReport["lines"][number]>): JournalReport["lines"][number] => ({
  line_id: "l", date: "2026-11-02", entry_id: "e1", entry: 7, source_type: "invoice", status: "posted", memo: null, description: null, contact: "Abebe",
  debit: "0.00", credit: "0.00", balance: "0.00", account_id: "a", account_code: "1100", account_name: "Accounts receivable", type: "invoice", number: "INV-1", ...o,
});

describe("the journal report", () => {
  it("has QuickBooks' columns with the account in place of the split and balance", () => {
    expect(chooseColumns({}, JOURNAL_COLUMNS, JOURNAL_DEFAULTS).slice(0, 8)).toEqual(["date", "type", "number", "name", "memo", "account", "debit", "credit"]);
    expect(JOURNAL_COLUMNS.map((c) => c.key)).not.toContain("balance");
    expect(JOURNAL_COLUMNS.map((c) => c.key)).not.toContain("split");
  });

  it("puts each entry's lines together with their totals", () => {
    const entries = journalEntries([
      line({ line_id: "1", debit: "1150.00" }),
      line({ line_id: "2", account_code: "4000", account_name: "Sales", credit: "1000.00" }),
      line({ line_id: "3", account_code: "2200", account_name: "VAT payable", credit: "150.00" }),
      line({ line_id: "4", entry_id: "e2", entry: 8, debit: "50.00" }),
      line({ line_id: "5", entry_id: "e2", entry: 8, credit: "50.00" }),
    ]);
    expect(entries.map((e) => [e.entryId, e.rows.length, e.debits, e.credits])).toEqual([["e1", 3, "1150.00", "1150.00"], ["e2", 2, "50.00", "50.00"]]);
    expect(entries[0].rows[1]).toMatchObject({ account: "4000 Sales", entry: "#7", name: "Abebe" });
  });
});

describe("the bin card", () => {
  it("names each move's document and keeps QuickBooks-like defaults", () => {
    expect(chooseColumns({}, BIN_COLUMNS, BIN_DEFAULTS)).toContain("balance_value");
    const r = binRow({ moved_on: "2026-11-03", kind: "sale", reference: "INV-1", qty_in: "0.00", qty_out: "2.00", unit_cost: "50.00", value: "-100.00",
      balance_qty: "8.00", balance_value: "400.00", document_id: "d1", document_type: "invoice", document_number: "INV-1", name: "Abebe", created_by: "Hana" }, 0);
    expect(r).toMatchObject({ document: "Invoice INV-1", name: "Abebe", created_by: "Hana", date: "2026-11-03" });
    expect(binRow({ moved_on: "2026-11-01", kind: "opening", reference: null, qty_in: "10.00", qty_out: "0.00", unit_cost: "50.00", value: "500.00",
      balance_qty: "10.00", balance_value: "500.00" }, 1).document).toBeNull();
  });
});

import { describe, expect, it } from "vitest";
import { actionLabel, changesOf, diffStates, type HistoryAuditRow } from "./history";

const row = (action: string, before: unknown, after: unknown): HistoryAuditRow => ({
  id: 1, action, user_id: "u", user_name: "Hana", before, after, reason: null, created_at: "2026-11-21T09:00:00Z",
});

describe("document history", () => {
  it("names each kind of event in words", () => {
    expect(actionLabel("edited_after_posting")).toBe("Corrected after posting");
    expect(actionLabel("posted")).toBe("Approved and posted");
    expect(actionLabel("something_new")).toBe("Something new");
  });

  it("lists a draft's changed fields and lines", () => {
    const changes = changesOf(row("draft_saved",
      JSON.stringify({ total: "2000.00", due_date: null, lines: ["Roasting service × 2.0000 @ 1000.00"] }),
      JSON.stringify({ total: "3000.00", due_date: "2026-12-01", lines: ["Roasting service × 3.0000 @ 1000.00"] })));
    expect(changes).toEqual([
      { kind: "field", label: "Due date", from: "—", to: "2026-12-01" },
      { kind: "field", label: "Total", from: "2000.00", to: "3000.00" },
      { kind: "line", change: "removed", text: "Roasting service × 2 @ 1000.00" },
      { kind: "line", change: "added", text: "Roasting service × 3 @ 1000.00" },
    ]);
  });

  it("reads a correction's own field-by-field list, with lines as objects", () => {
    const changes = changesOf(row("edited_after_posting", null, {
      changes: {
        total: { old: "317400.00", new: "264500.00" },
        amount_due: { old: "317400.00", new: "264500.00" },
        lines: {
          old: [{ description: "Roasted coffee", quantity: "240.0000", unit_price: "1150.00" }],
          new: [{ description: "Roasted coffee", quantity: "200.0000", unit_price: "1150.00" }],
        },
      },
      version_saved: 1,
    }));
    expect(changes).toEqual([
      { kind: "field", label: "Total", from: "317400.00", to: "264500.00" },
      { kind: "line", change: "removed", text: "Roasted coffee × 240 @ 1150.00" },
      { kind: "line", change: "added", text: "Roasted coffee × 200 @ 1150.00" },
    ]);
  });

  it("shows nothing for events that change no fields, and keeps unchanged lines out", () => {
    expect(changesOf(row("posted", null, JSON.stringify({ number: "INV-1" })))).toEqual([]);
    expect(diffStates({ lines: ["A × 1 @ 5.00", "B × 2 @ 1.00"] }, { lines: ["B × 2 @ 1.00", "A × 1 @ 5.00"] })).toEqual([]);
    expect(changesOf(row("draft_saved", "not json", "{}"))).toEqual([]);
  });
});

import { describe, expect, it } from "vitest";
import { REMINDERS_SHOWN, reminderDays, settingsBody, VAT_SHOWN } from "./settings";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("settingsBody", () => {
  it("sends only the fields on the form, so locked (disabled) fields are left alone", () => {
    const body = settingsBody(form([["version", "v1"], ["legal_name", " Abyssinia Coffee "], ["city", "Addis Ababa"]]));
    expect(body).toEqual({ version: "v1", legal_name: "Abyssinia Coffee", city: "Addis Ababa" });
    expect(body).not.toHaveProperty("base_currency");
  });

  it("clears an emptied optional field", () => {
    expect(settingsBody(form([["version", "v1"], ["trading_name", "  "]]))).toEqual({ version: "v1", trading_name: null });
  });

  it("reads the fiscal year start as a number and the VAT box only when it was shown", () => {
    expect(settingsBody(form([["version", "v1"], ["fiscal_year_start_month", "7"], [VAT_SHOWN, "1"]]))).toEqual({
      version: "v1", fiscal_year_start_month: 7, vat_registered: false,
    });
    expect(settingsBody(form([["version", "v1"], [VAT_SHOWN, "1"], ["vat_registered", "on"]]))).toEqual({ version: "v1", vat_registered: true });
    expect(settingsBody(form([["version", "v1"]]))).not.toHaveProperty("vat_registered");
  });
});

describe("approval limits", () => {
  it("are sent without separators, and an emptied one as null (no limit)", () => {
    const f = new FormData();
    f.append("version", "v1");
    f.append("approval.invoice_above", "50,000");
    f.append("approval.bill_above", "");
    f.append("approval.journal_above", " 10000.50 ");
    expect(settingsBody(f)).toEqual({ version: "v1", approval: { invoice_above: "50000", bill_above: null, journal_above: "10000.50" } });
    const none = new FormData();
    none.append("version", "v1");
    expect(settingsBody(none)).not.toHaveProperty("approval");
  });
});

describe("payment reminders", () => {
  it("reads the days after the due date, at most three", () => {
    expect(reminderDays("7, 30")).toEqual([7, 30]);
    expect(reminderDays(" 3 14;30 60 ")).toEqual([3, 14, 30]);
    expect(reminderDays("")).toEqual([]);
  });
  it("sends the reminder settings only when the form showed them", () => {
    expect(settingsBody(form([["version", "v1"]]))).toEqual({ version: "v1" });
    expect(settingsBody(form([["version", "v1"], [REMINDERS_SHOWN, "1"], ["reminders.days", "7, 30"]]))).toEqual({
      version: "v1", reminders: { enabled: false, days: [7, 30] },
    });
    expect(settingsBody(form([["version", "v1"], [REMINDERS_SHOWN, "1"], ["reminders.enabled", "on"], ["reminders.days", "14"]])).reminders)
      .toEqual({ enabled: true, days: [14] });
  });
});

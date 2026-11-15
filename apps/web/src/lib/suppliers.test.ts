import { describe, expect, it } from "vitest";
import { supplierBody, WITHHOLDING_SHOWN } from "./suppliers";

const form = (entries: [string, string][]) => {
  const f = new FormData();
  for (const [k, v] of entries) f.append(k, v);
  return f;
};

describe("supplierBody", () => {
  it("sends the fields on the form and clears emptied ones, the default account included", () => {
    expect(supplierBody(form([
      ["name", " Ethio Telecom "], ["tax_id", ""], ["payment_terms_days", "15"], ["default_expense_account_id", ""],
    ]))).toEqual({ name: "Ethio Telecom", tax_id: null, payment_terms_days: 15, default_expense_account_id: null });
  });

  it("builds the payout details without empty parts", () => {
    expect(supplierBody(form([
      ["payout.bank_name", "Commercial Bank of Ethiopia"], ["payout.account_number", " 1000123456789 "], ["payout.account_name", ""],
      ["payout.mobile_money_provider", ""], ["payout.mobile_money_number", ""],
    ]))).toEqual({ payout: { bank_name: "Commercial Bank of Ethiopia", account_number: "1000123456789" } });
  });

  it("reads the withholding box only when it was shown", () => {
    expect(supplierBody(form([[WITHHOLDING_SHOWN, "1"]]))).toEqual({ withholding_exempt: false });
    expect(supplierBody(form([[WITHHOLDING_SHOWN, "1"], ["withholding_exempt", "on"], ["withholding_exempt_reason", "WHT-EX-114"]])))
      .toEqual({ withholding_exempt: true, withholding_exempt_reason: "WHT-EX-114" });
    expect(supplierBody(form([["name", "x"]]))).toEqual({ name: "x" });
  });
});

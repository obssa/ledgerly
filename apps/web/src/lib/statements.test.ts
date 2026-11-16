import { describe, expect, it } from "vitest";
import type { Account } from "./api";
import { categorisable, signed, statementFrom, statementTab } from "./statements";

const account = (over: Partial<Account>): Account => ({ id: "x", code: "0000", name: "x", type: "expense", subtype: null, system_key: null, currency: null, is_active: true, ...over });

describe("statementTab", () => {
  it("reads the tab from the address bar and falls back to the lines to review", () => {
    expect(statementTab("excluded")).toBe("excluded");
    expect(statementTab("all")).toBe("all");
    expect(statementTab("reconciled")).toBe("imported");
    expect(statementTab(undefined)).toBe("imported");
  });
});

describe("categorisable", () => {
  it("offers active accounts other than the bank itself, leaving out the ones kept by documents", () => {
    const list = categorisable([
      account({ id: "charges", code: "6500", name: "Bank charges" }),
      account({ id: "bank", code: "1100", name: "Bank", type: "asset", subtype: "bank" }),
      account({ id: "ar", code: "1200", name: "Receivable", type: "asset", subtype: "receivable" }),
      account({ id: "ap", code: "2000", name: "Payable", type: "liability", subtype: "payable" }),
      account({ id: "old", code: "6900", name: "Old", is_active: false }),
      account({ id: "interest", code: "4900", name: "Interest income", type: "income" }),
      account({ id: "wallet", code: "1150", name: "telebirr", type: "asset", subtype: "mobile_money" }),
    ], "bank");
    expect(list.map((a) => a.id)).toEqual(["wallet", "interest", "charges"]);
  });
});

describe("statementFrom", () => {
  it("takes the uploaded file first, then pasted text, and refuses an empty form", async () => {
    const withFile = new FormData();
    withFile.append("file", new File(["Date,Amount\n05/11/2026,1\n"], "cbe-nov.csv", { type: "text/csv" }));
    withFile.append("csv", "ignored");
    expect(await statementFrom(withFile)).toEqual({ csv: "Date,Amount\n05/11/2026,1\n", fileName: "cbe-nov.csv" });

    const pasted = new FormData();
    pasted.append("file", new File([], ""));
    pasted.append("csv", "Date,Amount\n05/11/2026,1\n");
    expect(await statementFrom(pasted)).toEqual({ csv: "Date,Amount\n05/11/2026,1\n" });

    expect(await statementFrom(new FormData())).toHaveProperty("error");
    const big = new FormData();
    big.append("csv", "x".repeat(900_001));
    expect(await statementFrom(big)).toHaveProperty("error");
  });
});

describe("signed", () => {
  it("shows money in with a plus and money out with a minus, grouped", () => {
    expect(signed("1250.5")).toEqual({ text: "+1,250.50", direction: "in" });
    expect(signed("-115.00")).toEqual({ text: "−115.00", direction: "out" });
    expect(signed("-1234567.89")).toEqual({ text: "−1,234,567.89", direction: "out" });
  });
});

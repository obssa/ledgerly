import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { ADMIN, CREATE, SECTIONS, firstPage, locate, pathOf, searchEntries } from "./navigation";

const APP = join(__dirname, "..", "app", "(app)");
const pageExists = (href: string) => existsSync(join(APP, ...pathOf(href).split("/").filter(Boolean), "page.tsx"));

describe("navigation", () => {
  it("links only to pages that exist", () => {
    for (const item of [...SECTIONS, ADMIN].flatMap((s) => s.items).filter((i) => !i.soon)) {
      expect(pageExists(item.href), item.href).toBe(true);
    }
    for (const action of CREATE) expect(pageExists(action.href), action.href).toBe(true);
  });

  it("opens each section on its first page", () => {
    expect(firstPage(SECTIONS.find((s) => s.id === "sales")!).href).toBe("/customers");
    expect(firstPage(SECTIONS.find((s) => s.id === "reports")!).href).toBe("/reports");
    expect(firstPage(ADMIN).href).toBe("/settings");
    for (const s of [...SECTIONS, ADMIN].filter((s) => s.items.some((i) => !i.soon))) expect(pageExists(firstPage(s).href), s.id).toBe(true);
  });

  it("lists each page once", () => {
    const hrefs = [...SECTIONS, ADMIN].flatMap((s) => s.items.map((i) => i.href));
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it("places a page under the longest entry it starts with", () => {
    expect(locate("/")?.item.label).toBe("Overview");
    expect(locate("/invoices")?.item.label).toBe("Invoices");
    expect(locate("/invoices/0198-abc")?.item.label).toBe("Invoices");
    expect(locate("/invoices/recurring")?.item.label).toBe("Recurring invoices");
    expect(locate("/invoices/new")?.section.id).toBe("sales");
    expect(locate("/reports/balance-sheet")?.item.label).toBe("Balance sheet");
    expect(locate("/reports/export/pnl")?.item.label).toBe("Profit and loss");
    expect(locate("/settings/team")?.item.label).toBe("Team");
    expect(locate("/payments-made/new")?.item.label).toBe("Payments made");
    // A prefix of a word is not a match: /payments is not under /payments-made.
    expect(locate("/payments")?.item.label).toBe("Payments received");
    expect(locate("/no-access")).toBeNull();
  });

  it("finds pages and forms by what people call them", () => {
    expect(searchEntries("trial")[0].href).toBe("/reports/trial-balance");
    expect(searchEntries("vendors")[0].href).toBe("/suppliers");
    expect(searchEntries("new bill")[0].href).toBe("/bills/new");
    expect(searchEntries("p&l")[0].href).toBe("/reports");
    expect(searchEntries("telebirr").map((e) => e.href)).toContain("/banking");
    expect(searchEntries("")).toEqual([]);
    expect(searchEntries("zzzz")).toEqual([]);
  });
});

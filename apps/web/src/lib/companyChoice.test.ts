import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { chooseCompany } from "./companyChoice";

describe("choosing the company to work in", () => {
  it("takes the first company still in use", () => {
    expect(chooseCompany([{ id: "a", status: "archived" }, { id: "b", status: "active" }])).toEqual({ companyId: "b", to: "/" });
  });
  it("takes an archived company when there is nothing else (it can still be read)", () => {
    expect(chooseCompany([{ id: "a", status: "archived" }])).toEqual({ companyId: "a", to: "/" });
  });
  it("sends a person with no company to company setup", () => {
    expect(chooseCompany([])).toEqual({ to: "/onboarding" });
  });
});

/**
 * Company setup only creates a new company. A page that sent a signed-in person there because their company cookie
 * was missing stranded them: whatever they clicked, they came back to it (26 Nov 2026). Pages send them to
 * /company/resume, which picks their company again. Only sign-up goes to setup directly.
 */
describe("no page sends a signed-in person straight to company setup", () => {
  const APP = join(__dirname, "..", "app");
  const files = (dir: string): string[] =>
    readdirSync(dir).flatMap((n) => {
      const p = join(dir, n);
      return statSync(p).isDirectory() ? files(p) : /\.tsx?$/.test(n) ? [p] : [];
    });
  it("redirect(\"/onboarding\") appears only in sign-up", () => {
    const found = files(APP)
      .filter((f) => /redirect\(\s*["'`]\/onboarding/.test(readFileSync(f, "utf8")))
      .map((f) => relative(APP, f).replace(/\\/g, "/"));
    expect(found).toEqual(["(auth)/actions.ts"]);
    expect(readFileSync(join(APP, "(auth)", "actions.ts"), "utf8").match(/redirect\(\s*["'`]\/onboarding/g)).toHaveLength(1);
  });
  it("the data layer sends a missing company to /company/resume", () => {
    const data = readFileSync(join(__dirname, "data.ts"), "utf8");
    expect(data).not.toMatch(/redirect\(\s*["'`]\/onboarding/);
    expect(data.match(/redirect\("\/company\/resume"\)/g)?.length).toBeGreaterThanOrEqual(3);
  });
});

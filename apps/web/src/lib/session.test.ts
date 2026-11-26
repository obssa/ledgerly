import { beforeEach, describe, expect, it, vi } from "vitest";
import { ResponseCookies } from "next/dist/compiled/@edge-runtime/cookies";

/**
 * The session's cookies, on Next.js's own cookie store: the object cookies() returns inside a Server Function. In it,
 * a cookie deleted earlier in the same call is still returned by get(), as an entry with an empty value. On 26 Nov
 * 2026 that made every real sign-in look like a demo sign-in: the company cookie lived 2 hours, the token 12, and
 * after two hours every page sent the person to company setup.
 */
let jar: ResponseCookies;
vi.mock("server-only", () => ({}));
vi.mock("next/headers", () => ({ cookies: async () => jar }));
const { replaceToken, selectCompany, startSession } = await import("./session");

const TWELVE_HOURS = 60 * 60 * 12;
const TWO_HOURS = 60 * 60 * 2;
const life = (name: string) => jar.get(name)?.maxAge;

beforeEach(() => {
  jar = new ResponseCookies(new Headers());
});

describe("session cookies live as long as the session", () => {
  it("a real sign-in: token, name and company all last 12 hours", async () => {
    await startSession("token", "Hana Bekele", undefined, "company-1");
    expect(jar.get("ledgerly_company")?.value).toBe("company-1");
    expect([life("ledgerly_token"), life("ledgerly_name"), life("ledgerly_company")]).toEqual([TWELVE_HOURS, TWELVE_HOURS, TWELVE_HOURS]);
  });

  it("a company chosen in the same call as a real sign-in also lasts 12 hours (the 26 Nov bug)", async () => {
    await startSession("token", "Hana Bekele");   // deletes the demo cookie
    await selectCompany("company-1");             // used to read that deleted cookie as "demo"
    expect(life("ledgerly_company")).toBe(TWELVE_HOURS);
  });

  it("a demo sign-in: everything lasts the demo's 2 hours", async () => {
    await startSession("token", "Demo", "Owner", "demo-company");
    expect([life("ledgerly_token"), life("ledgerly_company"), life("ledgerly_demo")]).toEqual([TWO_HOURS, TWO_HOURS, TWO_HOURS]);
    await selectCompany("demo-company-2");
    expect(life("ledgerly_company")).toBe(TWO_HOURS);
  });

  it("a sign-in without a company leaves no company behind", async () => {
    jar.set("ledgerly_company", "someone-elses", { maxAge: TWELVE_HOURS });
    await startSession("token", "New person");
    expect(jar.get("ledgerly_company")?.value).toBe("");
  });

  it("a new token after a password change renews the name and company with it", async () => {
    jar.set("ledgerly_name", "Hana Bekele", { maxAge: 60 });
    jar.set("ledgerly_company", "company-1", { maxAge: 60 });
    await replaceToken("new-token");
    expect([life("ledgerly_token"), life("ledgerly_name"), life("ledgerly_company")]).toEqual([TWELVE_HOURS, TWELVE_HOURS, TWELVE_HOURS]);
    expect(jar.get("ledgerly_company")?.value).toBe("company-1");
  });
});

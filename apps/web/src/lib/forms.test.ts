import { describe, expect, it } from "vitest";
import { ApiError } from "./api";
import { formStateFromError, text } from "./forms";

describe("formStateFromError", () => {
  it("shows the API's first validation message and keeps the field errors", () => {
    const err = new ApiError(422, "The given data was invalid.", {
      message: "The given data was invalid.",
      errors: { email: ["These credentials do not match our records."] },
    });
    expect(formStateFromError(err)).toEqual({
      error: "These credentials do not match our records.",
      fields: { email: ["These credentials do not match our records."] },
    });
  });

  it("shows the lockout message", () => {
    const err = new ApiError(429, "Too many", { errors: { email: ["Too many sign-in attempts. Try again in 15 minutes."] } });
    expect(formStateFromError(err).error).toBe("Too many sign-in attempts. Try again in 15 minutes.");
  });

  it("never shows server details for other failures", () => {
    expect(formStateFromError(new ApiError(500, "SQLSTATE[...] secret"))).toEqual({
      error: "Evenbooks could not be reached. Try again in a minute.",
    });
    expect(formStateFromError(new TypeError("fetch failed")).error).toBe("Evenbooks could not be reached. Try again in a minute.");
  });
});

describe("text", () => {
  it("trims and treats blank as not given", () => {
    const form = new FormData();
    form.set("a", "  Hana  ");
    form.set("b", "   ");
    expect(text(form, "a")).toBe("Hana");
    expect(text(form, "b")).toBeUndefined();
    expect(text(form, "missing")).toBeUndefined();
  });
});

describe("text() of a date field", () => {
  const form = (entries: [string, string][]) => { const f = new FormData(); for (const [k, v] of entries) f.append(k, v); return f; };

  it("reads what was typed the company's way, before the stored value, with or without JavaScript", () => {
    expect(text(form([["issue_date", "2026-11-01"], ["issue_date__typed", "27/11/2026"], ["__date_prefs", "DD/MM/YYYY|gregorian"]]), "issue_date")).toBe("2026-11-27");
    expect(text(form([["due_date", ""], ["due_date__typed", "12/31/2026"], ["__date_prefs", "MM/DD/YYYY|gregorian"]]), "due_date")).toBe("2026-12-31");
    expect(text(form([["d", ""], ["d__typed", "17/03/2019"], ["__date_prefs", "DD/MM/YYYY|ethiopian"]]), "d")).toBe("2026-11-26");
  });

  it("passes on a date it cannot read, for the API to refuse; an emptied field is not given", () => {
    expect(text(form([["d", "2026-11-01"], ["d__typed", "31/02/2026"], ["__date_prefs", "DD/MM/YYYY|gregorian"]]), "d")).toBe("31/02/2026");
    expect(text(form([["d", "2026-11-01"], ["d__typed", "  "], ["__date_prefs", "DD/MM/YYYY|gregorian"]]), "d")).toBeUndefined();
    expect(text(form([["d", "2026-11-01"]]), "d")).toBe("2026-11-01");
  });
});

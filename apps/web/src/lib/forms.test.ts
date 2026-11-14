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
      error: "Ledgerly could not be reached. Try again in a minute.",
    });
    expect(formStateFromError(new TypeError("fetch failed")).error).toBe("Ledgerly could not be reached. Try again in a minute.");
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

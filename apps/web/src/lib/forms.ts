import { DATE_PREFS_FIELD, TYPED, parseDate, pickDateFormat } from "./dates";
import { ApiError } from "./api";

/** What a form shows after a failed submit: one message, and the fields the API rejected. */
export interface FormState {
  error?: string;
  /** Something went right and is worth saying, such as "we sent you an email". */
  notice?: string;
  /** The API's name for a refusal the screen reacts to, such as "EmailNotVerified". */
  code?: string;
  fields?: Record<string, string[]>;
  /** A document saved with files still to send (112): the browser sends them to the upload route, then opens href. */
  next?: { kind: "invoice" | "bill" | "expense"; id: string; href: string };
}

/**
 * Turns an API failure into a message for the person filling in the form. Validation (422), lockout (429)
 * and "already done" (409) messages come from the API and are meant to be shown; anything else gets a general
 * message, so server details never reach the page.
 */
export function formStateFromError(error: unknown): FormState {
  if (error instanceof ApiError && [409, 422, 429].includes(error.status)) {
    const body = (error.body ?? {}) as { message?: string; errors?: Record<string, string[]> };
    const fields = body.errors ?? {};
    const first = Object.values(fields)[0]?.[0];
    return { error: first ?? body.message ?? "Check the highlighted fields.", fields };
  }
  // A refusal with a name is written for the person: the email address is not confirmed yet, and so on.
  if (error instanceof ApiError && error.status === 403) {
    const body = (error.body ?? {}) as { message?: string; type?: string };
    if (body.type && body.message) return { error: body.message, code: body.type };
  }
  if (error instanceof ApiError && error.status === 0) {
    return { error: "This is the demo: there is no server to sign in to." };
  }
  return { error: "Evenbooks could not be reached. Try again in a minute." };
}

/** Text from a form field; empty means not given. A date field (DateInput) gives its date as stored, "YYYY-MM-DD". */
export function text(form: FormData, name: string): string | undefined {
  const typed = form.get(`${name}${TYPED}`);
  if (typeof typed === "string") return typedDate(typed, form.get(DATE_PREFS_FIELD));
  const value = form.get(name);
  return typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
}

/**
 * What was typed in a date field, read the company's way, as the stored "YYYY-MM-DD". What was typed is what counts,
 * with or without JavaScript; a date that cannot be read is passed on as typed, for the API to refuse with its message.
 */
function typedDate(typed: string, prefs: FormDataEntryValue | null): string | undefined {
  const t = typed.trim();
  if (t === "") return undefined;
  const [format, calendar] = (typeof prefs === "string" ? prefs : "").split("|");
  return parseDate(t, { dateFormat: pickDateFormat(format), calendar: calendar === "ethiopian" ? "ethiopian" : "gregorian" }) ?? t;
}

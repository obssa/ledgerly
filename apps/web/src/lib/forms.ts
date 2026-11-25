import { ApiError } from "./api";

/** What a form shows after a failed submit: one message, and the fields the API rejected. */
export interface FormState {
  error?: string;
  /** Something went right and is worth saying, such as "we sent you an email". */
  notice?: string;
  /** The API's name for a refusal the screen reacts to, such as "EmailNotVerified". */
  code?: string;
  fields?: Record<string, string[]>;
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

/** Text from a form field; empty means not given. */
export function text(form: FormData, name: string): string | undefined {
  const value = form.get(name);
  return typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
}

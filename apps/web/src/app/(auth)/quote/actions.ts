"use server";

import { ApiError, ledgerly } from "@/lib/api";
import type { FormState } from "@/lib/forms";

/** The customer's answer through the quote's link (065): no session; the API checks the token and answers once. */
export async function answerQuote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const value = (n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");
  const answer = value("answer") === "declined" ? "declined" : "accepted";
  if (value("name").length < 2) return { error: "Your name, as it goes on the record of the answer." };
  try {
    await ledgerly.answerPublicQuote({ token: value("token"), answer, name: value("name"), ...(value("note") ? { note: value("note") } : {}) });
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) {
      const errors = (error.body as { errors?: Record<string, string[]> } | null)?.errors;
      return { error: errors?.answer?.[0] ?? errors?.name?.[0] ?? "This link is not valid any more: ask the company to send the quote again." };
    }
    if (error instanceof ApiError && error.status === 429) return { error: "Too many tries: wait a minute and try again." };
    throw error;
  }
  return { notice: answer === "accepted" ? "Thank you: the company has your acceptance." : "Thank you: the company knows you declined." };
}

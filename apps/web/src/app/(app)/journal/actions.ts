"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { journalBody, recurringBody } from "@/lib/journal";
import { getSession } from "@/lib/session";

/* Server Functions behind manual journals. The API checks who may post (journals.create) and every rule. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot post journal entries." };
  return formStateFromError(error);
}

export async function postJournal(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let to: string;
  try {
    const result = await ledgerly.postJournal(journalBody(form), ctx);
    // Above the company's approval limit the journal waits for someone else instead of posting.
    to = "request" in result ? `/journal/requests/${encodeURIComponent(result.request.id)}` : `/journal/${encodeURIComponent(result.id)}`;
  } catch (error) {
    return refusal(error);
  }
  redirect(to);
}

export async function reverseJournal(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.reverseJournal(text(form, "id") ?? "", { date: text(form, "date") ?? "", reason: text(form, "reason") ?? "" }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  refresh();
  redirect(`/journal/${encodeURIComponent(id)}`);
}

/** Set up a recurring journal, or save changes to one (it then posts as you). */
export async function saveRecurring(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.saveRecurringJournal(text(form, "id") ?? null, recurringBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  refresh();
  redirect(`/journal/recurring/${encodeURIComponent(id)}`);
}

export async function recurringStep(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const step = text(form, "step");
  if (step !== "pause" && step !== "resume") return { error: "Choose pause or resume." };
  try {
    const nextOn = text(form, "next_on");
    await ledgerly.recurringJournalStep(text(form, "id") ?? "", step, step === "resume" && nextOn ? { next_on: nextOn } : {}, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: step === "pause" ? "Paused: nothing posts until it is resumed." : "Resumed: it posts as you from now on." };
}

export async function postDueRecurring(): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.postDueRecurring(ctx);
    refresh();
    const paused = done.data.filter((r) => r.error);
    return paused.length > 0
      ? { error: `Posted ${done.posted} entries. Paused: ${paused.map((r) => `${r.name} (${r.error})`).join("; ")}` }
      : { notice: `Posted ${done.posted} ${done.posted === 1 ? "entry" : "entries"}.` };
  } catch (error) {
    return refusal(error);
  }
}

/** Approve, reject (with a note) or withdraw a manual journal waiting for approval. */
export async function decideJournalRequest(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const step = text(form, "step");
  if (step !== "approve" && step !== "reject" && step !== "withdraw") return { error: "Choose what to do with the request." };
  const id = text(form, "id") ?? "";
  let posted: string | null = null;
  try {
    const result = await ledgerly.decideJournalRequest(id, step, step === "reject" ? { note: text(form, "note") ?? "" } : {}, ctx);
    if (step === "approve" && "number" in result) posted = result.id;
  } catch (error) {
    return refusal(error);
  }
  if (posted) redirect(`/journal/${encodeURIComponent(posted)}`);
  refresh();
  return { notice: step === "reject" ? "Rejected. The person who asked sees your note." : "Withdrawn." };
}

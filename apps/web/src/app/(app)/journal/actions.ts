"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { journalBody } from "@/lib/journal";
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
  let id: string;
  try {
    id = (await ledgerly.postJournal(journalBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/journal/${encodeURIComponent(id)}`);
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

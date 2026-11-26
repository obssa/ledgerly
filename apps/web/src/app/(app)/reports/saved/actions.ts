"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { savedReportBody } from "@/lib/saved-reports";
import { getSession } from "@/lib/session";

/* Saving, changing and removing a saved report. The API keeps each person's to themselves and checks every option. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot read reports." };
  return formStateFromError(error);
}

export async function saveReport(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? null;
  try {
    const saved = await ledgerly.saveReport(id, savedReportBody(form), ctx);
    refresh();
    return { notice: saved.schedule === "none" ? `Saved ${saved.name}.` : `Saved ${saved.name}. It is sent to you next on ${saved.next_send_on}.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function removeSavedReport(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.deleteSavedReport(text(form, "id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Removed." };
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind the year-end close. The API checks who may close (periods.close) and reopen (periods.reopen). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Only an owner or admin can do this." };
  return formStateFromError(error);
}

export async function closeYear(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.closeYear(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: `Year closed: ${done.net_profit} moved to retained earnings (entry #${done.entry.number}).` };
  } catch (error) {
    return refusal(error);
  }
}

export async function reopenYear(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.reopenYear(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
    refresh();
    return { notice: `Year reopened: its closing entry is reversed (entry #${done.reversal.number}).` };
  } catch (error) {
    return refusal(error);
  }
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";
import { settingsBody } from "@/lib/settings";

/*
 * Server Functions behind the company settings screen. The API decides who may change what (company.manage,
 * periods.close, periods.reopen); the screen only hides what a role cannot use.
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot change this. Ask an owner or admin." };
  return formStateFromError(error);
}

export async function saveCompanySettings(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.saveCompanySettings(settingsBody(form), ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh(); // shows the saved values and the new version
  return { notice: "Company settings saved." };
}

export async function setPeriodLock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    // An earlier date, or "no period closed", reopens periods: the API asks for periods.reopen and a reason.
    const { books_closed_until } = await ledgerly.setPeriodLock(
      { books_closed_until: form.get("clear") === "on" ? null : (text(form, "books_closed_until") ?? null), reason: text(form, "reason") },
      ctx,
    );
    refresh();
    return { notice: books_closed_until ? `Books are closed up to ${books_closed_until}.` : "No period is closed now." };
  } catch (error) {
    return refusal(error);
  }
}

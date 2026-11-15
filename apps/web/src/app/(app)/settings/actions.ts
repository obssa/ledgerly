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

export async function setCurrency(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const currency = text(form, "currency") ?? "";
  const enabled = form.get("enabled") !== "false";
  try {
    await ledgerly.setCurrency({ currency, enabled }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: enabled ? `${currency} added.` : `${currency} turned off. Its rates and documents are kept.` };
}

export async function addExchangeRate(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body = { currency: text(form, "currency") ?? "", rate_date: text(form, "rate_date") ?? "", rate: (text(form, "rate") ?? "").replace(/,/g, "") };
  try {
    await ledgerly.addExchangeRate(body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Rate saved: 1 ${body.currency} = ${body.rate} on ${body.rate_date}.` };
}

export async function saveNumbering(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const saved = await ledgerly.saveNumbering({
      doc_type: text(form, "doc_type") ?? "",
      mode: form.get("mode") === "manual" ? "manual" : "automatic",
      prefix: text(form, "prefix") ?? "",
      include_year: form.get("include_year") === "on",
      padding: Number(text(form, "padding") ?? 4),
    }, ctx);
    refresh();
    return { notice: saved.mode === "manual" ? "Saved. Numbers are typed from your invoice book." : `Saved. The next number will be ${saved.next_number}.` };
  } catch (error) {
    return refusal(error);
  }
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

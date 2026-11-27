"use server";

import { companyDate } from "@/lib/data";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession, selectCompany } from "@/lib/session";
import { formContentBody, settingsBody } from "@/lib/settings";

/*
 * Server Functions behind the company settings screen. The API decides who may change what (company.manage,
 * periods.close, periods.reopen); the screen only hides what a role cannot use.
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
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

/** QuickBooks' Customize (111): sales and purchase form content, class and location tracking, custom fields. */
export async function saveFormContent(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.saveCompanySettings({ version: text(form, "version") ?? "", form_content: formContentBody(form) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Saved. New invoices and bills show these fields." };
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
  return { notice: `Rate saved: 1 ${body.currency} = ${body.rate} on ${await companyDate(body.rate_date)}.` };
}

export async function saveNumbering(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const saved = await ledgerly.saveNumbering({
      doc_type: text(form, "doc_type") ?? "",
      mode: form.get("mode") === "manual" ? "manual" : "automatic",
      prefix: text(form, "prefix") ?? "",
      include_year: form.get("include_year") === "on",
      allow_custom: form.get("allow_custom") === "on",
      padding: Number(text(form, "padding") ?? 4),
    }, ctx);
    refresh();
    return { notice: saved.mode === "manual" ? "Saved. Numbers are typed from your invoice book." : `Saved. The next number will be ${saved.next_number}.` };
  } catch (error) {
    return refusal(error);
  }
}

/** A term from the form (109): days, or a day of the month and how near it a document goes to the next month. */
function termBody(form: FormData) {
  const kind = form.get("kind") === "day_of_month" ? "day_of_month" as const : "days" as const;
  const whole = (name: string) => (text(form, name) === undefined ? null : Number(text(form, name)));
  return {
    name: text(form, "name") ?? "",
    kind,
    days: kind === "days" ? whole("days") : null,
    day_of_month: kind === "day_of_month" ? whole("day_of_month") : null,
    next_month_within: kind === "day_of_month" ? whole("next_month_within") ?? 0 : 0,
  };
}

export async function addPaymentTerm(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const saved = await ledgerly.createPaymentTerm(termBody(form), ctx);
    refresh();
    return { notice: `${saved.name} added to the Terms list.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function savePaymentTerm(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const saved = await ledgerly.updatePaymentTerm(text(form, "id") ?? "", { ...termBody(form), is_active: form.get("is_active") === "on" }, ctx);
    refresh();
    return { notice: saved.is_active ? "Saved." : `Saved. ${saved.name} is turned off: documents on it keep it, new ones cannot choose it.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function addDimension(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body = { kind: text(form, "kind") ?? "", code: text(form, "code") ?? "", name: text(form, "name") ?? "" };
  try {
    await ledgerly.addDimension(body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `${body.code} added.` };
}

export async function toggleDimension(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const active = form.get("is_active") === "true";
  try {
    await ledgerly.updateDimension(text(form, "id") ?? "", { is_active: active }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: active ? "Turned on." : "Turned off. Old entries keep it." };
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


/** The demo's "Start fresh": a new, empty demo company; this session moves to it. */
export async function demoStartFresh(): Promise<FormState> {
  const ctx = await context();
  let company: string;
  try {
    company = (await ledgerly.demoStartFresh(ctx)).company_id;
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
    return refusal(error);
  }
  await selectCompany(company);
  redirect("/");
}

/** The demo's "Fill with demo data": the sample books, put in by the server in the next minute or two. */
export async function demoFill(): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.demoFill(ctx);
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
    return refusal(error);
  }
  return { notice: "Filling with the sample books. They are ready in a minute or two: refresh to see them." };
}

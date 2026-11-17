"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { projectBody, timeBody } from "@/lib/projects";
import { getSession } from "@/lib/session";

/* The Server Function behind the project forms. The API checks who may manage projects (projects.manage) and every rule. */

export async function saveProject(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  const id = text(form, "id") ?? null;
  let saved: string;
  try {
    saved = (await ledgerly.saveProject(id, projectBody(form, id === null), { token, companyId })).id;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot manage projects." };
    return formStateFromError(error);
  }
  if (id) {
    refresh();
    return { notice: "Saved." };
  }
  redirect(`/projects/${encodeURIComponent(saved)}`);
}

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown, what: string): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: `Your role cannot ${what}.` };
  return formStateFromError(error);
}

export async function logTime(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const t = await ledgerly.logTime(timeBody(form), ctx);
    refresh();
    return { notice: `Logged ${t.hours} hours on ${t.project.code}.` };
  } catch (error) {
    return refusal(error, "log hours");
  }
}

export async function removeTime(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.removeTime(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: "Removed." };
  } catch (error) {
    return refusal(error, "remove these hours");
  }
}

/** Bill the project's billable, unbilled hours on a draft invoice, then open it to check and post. */
export async function billTime(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let invoice: string;
  try {
    invoice = (await ledgerly.billTime(text(form, "project_id") ?? "", { issue_date: text(form, "issue_date") ?? "", income_account_id: text(form, "income_account_id") ?? "",
      up_to: text(form, "up_to") ?? undefined }, ctx)).invoice.id;
  } catch (error) {
    return refusal(error, "bill time");
  }
  redirect(`/invoices/${encodeURIComponent(invoice)}`);
}

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
  if (!companyId) redirect("/company/resume");
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
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown, what: string): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: `Your role cannot ${what}.` };
  return formStateFromError(error);
}

/** 093: sets the project up as a grant: donor, award, reference, restricted or not, and its budget lines. */
export async function saveGrant(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const lines: { account_id: string; amount: string }[] = [];
  for (let i = 0; i < 200 && form.has(`lines.${i}.account_id`); i++) {
    const account = text(form, `lines.${i}.account_id`);
    if (account) lines.push({ account_id: account, amount: (text(form, `lines.${i}.amount`) ?? "0").replace(/[,\s]/g, "") });
  }
  try {
    await ledgerly.setGrant(text(form, "id") ?? "", {
      donor_id: text(form, "donor_id") ?? null, award: (text(form, "award") ?? "").replace(/[,\s]/g, ""), reference: text(form, "reference") ?? null,
      restricted: form.get("restricted") === "on", lines,
    }, ctx);
    refresh();
    return { notice: "Grant saved." };
  } catch (error) {
    return refusal(error, "manage grants");
  }
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

/** Module 9a: a milestone, given as an amount or a share of the contract value. */
export async function addMilestone(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const amount = (text(form, "amount") ?? "").replace(/,/g, "");
  const percent = (text(form, "percent") ?? "").replace(/,/g, "");
  try {
    await ledgerly.addMilestone(text(form, "project_id") ?? "", {
      name: text(form, "name") ?? "", ...(amount ? { amount } : percent ? { percent } : {}), due_on: text(form, "due_on") ?? null,
    }, ctx);
  } catch (error) {
    return refusal(error, "manage projects");
  }
  refresh();
  return { notice: "Milestone added." };
}

export async function removeMilestone(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.removeMilestone(text(form, "project_id") ?? "", text(form, "milestone_id") ?? "", ctx);
  } catch (error) {
    return refusal(error, "manage projects");
  }
  refresh();
  return { notice: "Removed." };
}

/** Bills the milestone on a draft invoice to the project's customer, then opens it to check and post. */
export async function billMilestone(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let invoice: string;
  try {
    invoice = (await ledgerly.billMilestone(text(form, "project_id") ?? "", text(form, "milestone_id") ?? "",
      { issue_date: text(form, "issue_date") ?? "", income_account_id: text(form, "income_account_id") ?? "" }, ctx)).invoice.id;
  } catch (error) {
    return refusal(error, "bill milestones");
  }
  redirect(`/invoices/${encodeURIComponent(invoice)}`);
}

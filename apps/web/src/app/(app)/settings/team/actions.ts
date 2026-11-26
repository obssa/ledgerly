"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Module 11: inviting and managing the company's people. The API and the database decide who may change whom. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

/** The permissions ticked for a custom role. */
function chosen(form: FormData): string[] {
  return form.getAll("permissions").map(String).filter((p) => /^[a-z]+\.[a-z_]+$/.test(p));
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) {
    const demo = (error.body as { type?: string } | null)?.type === "DemoAccount";
    return { error: demo ? "Not available in the demo: invitations are not sent from the shared demo accounts." : "Only owners and admins manage the people of the company." };
  }
  return formStateFromError(error);
}

export async function inviteMember(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const role = text(form, "role") ?? "";
    const m = await ledgerly.inviteMember({ email: text(form, "email") ?? "", name: text(form, "name"), role, ...(role === "custom" ? { permissions: chosen(form) } : {}) }, ctx);
    refresh();
    return { notice: `Invited ${m.user.email}. The link in their email works for 7 days.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function changeMember(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const action = text(form, "action");
  if (action !== "role" && action !== "resend" && action !== "deactivate" && action !== "reactivate") return { error: "Choose what to do." };
  try {
    const m = await ledgerly.changeMember(text(form, "id") ?? "", action, text(form, "role"), ctx, chosen(form));
    refresh();
    const done = { role: m.role === "custom" ? `${m.user.name}'s access is saved.` : `${m.user.name} is now ${m.role}.`, resend: `Sent again to ${m.user.email}.`, deactivate: `${m.user.name} is deactivated.`, reactivate: `${m.user.name} is active again.` };
    return { notice: done[action] };
  } catch (error) {
    return refusal(error);
  }
}

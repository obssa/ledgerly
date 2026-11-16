"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind the chart of accounts. The API checks who may change it (accounting.chart) and every rule. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot change the chart of accounts." };
  return formStateFromError(error);
}

export async function addAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.addAccount({ code: text(form, "code") ?? "", name: text(form, "name") ?? "", type: text(form, "type") ?? "",
      ...(text(form, "kind") ? { kind: text(form, "kind") } : {}), ...(text(form, "parent_id") ? { parent_id: text(form, "parent_id") } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Account ${text(form, "code")} added.` };
}

export async function changeAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: Record<string, unknown> = {};
  for (const name of ["code", "name"]) if (form.has(name)) body[name] = text(form, name) ?? "";
  if (form.has("kind")) body.kind = text(form, "kind") ?? null;
  if (form.has("parent_id")) body.parent_id = text(form, "parent_id") ?? null;
  if (form.has("is_active")) body.is_active = form.get("is_active") === "true";
  try {
    await ledgerly.changeAccount(text(form, "id") ?? "", body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: form.has("is_active") ? (body.is_active ? "Restored." : "Archived.") : "Saved." };
}

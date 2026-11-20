"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly, type TagKind } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind custom tags (094). The API checks who may name tags and who may tag each transaction. */

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

export async function addTag(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const t = await ledgerly.addTag(text(form, "name") ?? "", ctx);
    refresh();
    return { notice: `Tag ${t.name} added.` };
  } catch (error) {
    return refusal(error, "add tags");
  }
}

export async function changeTag(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: { name?: string; is_active?: boolean } = {};
  if (form.has("name")) body.name = text(form, "name") ?? "";
  if (form.has("is_active")) body.is_active = form.get("is_active") === "1";
  try {
    const t = await ledgerly.changeTag(text(form, "id") ?? "", body, ctx);
    refresh();
    return { notice: body.is_active === undefined ? `Renamed ${t.name}.` : t.is_active ? `${t.name} is on again.` : `${t.name} is turned off.` };
  } catch (error) {
    return refusal(error, "change tags");
  }
}

export async function setTransactionTags(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const kind = text(form, "kind") as TagKind;
  try {
    await ledgerly.setTransactionTags(kind, text(form, "id") ?? "", form.getAll("tag_ids").map(String), ctx);
    refresh();
    return { notice: "Tags saved." };
  } catch (error) {
    return refusal(error, "tag this");
  }
}

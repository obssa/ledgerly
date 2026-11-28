"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind bins (098). The API checks who may change them (inventory.items). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot change bins." };
  return formStateFromError(error);
}

export async function addBin(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const b = await ledgerly.addBin({ warehouse_id: text(form, "warehouse_id") ?? "", code: text(form, "code") ?? "", name: text(form, "name") || null }, ctx);
    refresh();
    return { notice: `Bin ${b.code} added to ${b.warehouse}.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function changeBin(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: { code?: string; name?: string | null; is_active?: boolean } = {};
  if (form.has("code")) body.code = text(form, "code") ?? "";
  if (form.has("name")) body.name = text(form, "name") || null;
  if (form.has("is_active")) body.is_active = form.get("is_active") === "1";
  try {
    const b = await ledgerly.changeBin(text(form, "id") ?? "", body, ctx);
    refresh();
    return { notice: body.is_active === undefined ? `Bin ${b.code} saved.` : b.is_active ? `Bin ${b.code} is back.` : `Bin ${b.code} is archived.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function setBinItems(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const b = await ledgerly.setBinItems(text(form, "id") ?? "", form.getAll("item_ids").map(String), ctx);
    refresh();
    return { notice: `${b.items.length} product${b.items.length === 1 ? "" : "s"} kept in ${b.code}.` };
  } catch (error) {
    return refusal(error);
  }
}

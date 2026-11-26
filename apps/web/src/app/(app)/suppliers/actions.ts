"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";
import { supplierBody } from "@/lib/suppliers";

/* Server Functions behind the supplier screens. The API decides who may add (contacts.create) and edit (contacts.edit). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this. Ask an accountant, owner or admin." };
  return formStateFromError(error);
}

export async function createSupplier(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createSupplier(supplierBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/suppliers/${encodeURIComponent(id)}`);
}

export async function saveSupplier(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.updateSupplier(text(form, "id") ?? "", supplierBody(form), ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Supplier saved." };
}

export async function setSupplierActive(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const active = form.get("is_active") === "true";
  try {
    await ledgerly.updateSupplier(text(form, "id") ?? "", { is_active: active }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: active ? "Supplier restored." : "Supplier archived. Their bills and history are kept." };
}

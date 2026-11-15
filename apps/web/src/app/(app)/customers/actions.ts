"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { customerBody } from "@/lib/customers";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind the customer screens. The API decides who may add (contacts.create) and edit (contacts.edit). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this. Ask an accountant, owner or admin." };
  return formStateFromError(error);
}

export async function createCustomer(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createCustomer(customerBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/customers/${encodeURIComponent(id)}`);
}

export async function saveCustomer(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  try {
    await ledgerly.updateCustomer(id, customerBody(form), ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Customer saved." };
}

export async function setCustomerActive(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const active = form.get("is_active") === "true";
  try {
    await ledgerly.updateCustomer(text(form, "id") ?? "", { is_active: active }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: active ? "Customer restored." : "Customer archived. Their invoices and history are kept." };
}

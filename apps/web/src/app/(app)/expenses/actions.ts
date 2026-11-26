"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { getSession } from "@/lib/session";

/* Server Functions behind the expense screens. The API decides who may record (expenses.create) and void (transactions.edit). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/** Records and posts the expense at once: the money has already been spent. */
export async function createExpense(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createExpense(invoiceBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/expenses/${encodeURIComponent(id)}`);
}

export async function voidExpense(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.voidExpense(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Voided. The number is kept and the entry reversed." };
}

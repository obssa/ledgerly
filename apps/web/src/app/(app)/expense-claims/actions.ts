"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind expense claims (083). The API checks who may claim (expenses.claim) and whose claim it is. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot claim expenses." };
  return formStateFromError(error);
}

/** Receipts are rows `desc.N`, `amount.N`, `account.N`, `tax.N`; a row without a description or an amount is left out. */
export async function createExpenseClaim(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const lines: Record<string, string>[] = [];
  for (let i = 0; i < 20; i++) {
    const description = text(form, `desc.${i}`);
    const amount = (text(form, `amount.${i}`) ?? "").replace(/[,\s]/g, "");
    if (!description && !amount) continue;
    lines.push({ description: description ?? "", amount, account_id: text(form, `account.${i}`) ?? "", ...(text(form, `tax.${i}`) ? { tax_rate_id: text(form, `tax.${i}`) ?? "" } : {}) });
  }
  if (lines.length === 0) return { error: "Add at least one receipt." };
  let id: string;
  try {
    id = (await ledgerly.createExpenseClaim({ issue_date: text(form, "issue_date") ?? "", notes: text(form, "notes") || null, lines }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/expense-claims/${encodeURIComponent(id)}`);
}

export async function submitExpenseClaim(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.submitExpenseClaim(text(form, "id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Sent for approval." };
}

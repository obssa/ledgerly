"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the cash sale screens (070). The API decides who may record one (invoices.create and
 * payments.receive) and void it (transactions.edit), and recalculates every amount.
 */

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

/** Records and posts the cash sale at once: the money is already in. */
export async function createSalesReceipt(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createSalesReceipt(invoiceBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/sales-receipts/${encodeURIComponent(id)}`);
}

export async function voidSalesReceipt(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.voidSalesReceipt(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Voided. The number is kept, the entry reversed and any goods are back in stock." };
}

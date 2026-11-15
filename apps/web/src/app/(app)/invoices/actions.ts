"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the invoice screens. The API recalculates every amount and decides who may save
 * (invoices.create), approve (invoices.approve) and void (transactions.edit).
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  // A rule of the books (closed period, approval limit, state) arrives as 422 with a message for the person.
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/** Saves the draft; with intent=approve also posts it. Either way the invoice page opens. */
export async function createInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createInvoice(invoiceBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  if (form.get("intent") === "approve") {
    try {
      await ledgerly.approveInvoice(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
      // The draft is saved. A fixed flag, never text in the URL: the invoice page says so, and Approve there says why.
      redirect(`/invoices/${encodeURIComponent(id)}?posted=0`);
    }
  }
  redirect(`/invoices/${encodeURIComponent(id)}`);
}

export async function invoiceAction(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  const action = form.get("action");
  try {
    if (action === "approve") await ledgerly.approveInvoice(id, ctx);
    else if (action === "submit") await ledgerly.submitInvoice(id, ctx);
    else if (action === "void") await ledgerly.voidInvoice(id, text(form, "reason") ?? "", ctx);
    else return { error: "Unknown action." };
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: action === "approve" ? "Posted." : action === "submit" ? "Sent for approval." : "Voided. The number is kept and the entry reversed." };
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { settleProblem, settledIn } from "@/lib/payments";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { paymentBody } from "@/lib/payments";
import { getSession } from "@/lib/session";

/* Server Functions behind the payment screens. The API checks who may receive (payments.receive) and reverse (payments.reverse). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
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

export async function receivePayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  const settled = settledIn(form);
  const missing = settleProblem(settled, settled.length, form.get("keep_as_credit") === "on", "invoice");
  if (missing) return { error: missing };
  try {
    id = (await ledgerly.receivePayment(paymentBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/payments/${encodeURIComponent(id)}`);
}

export async function reversePayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.reversePayment(text(form, "id") ?? "", { date: text(form, "date") ?? "", reason: text(form, "reason") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Payment reversed. Its invoices owe again." };
}

/** A cheque received that bounced: reversed on the bank's date, its invoices owe again, the bank's charge booked. */
export async function bounceCheque(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const fee = (text(form, "fee") ?? "").replace(/[,\s]/g, "");
  try {
    await ledgerly.bounceCheque(text(form, "id") ?? "", { date: text(form, "date") ?? "", ...(fee ? { fee } : {}), ...(text(form, "reason") ? { reason: text(form, "reason") } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Recorded as bounced. Its invoices owe again." };
}

/** Applies a customer's unused credit on this payment to one of their open invoices. */
export async function allocatePayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.allocatePayment(text(form, "id") ?? "", { allocations: [{ invoice_id: text(form, "document_id") ?? "", amount: (text(form, "amount") ?? "").replace(/,/g, "") }] }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Applied." };
}

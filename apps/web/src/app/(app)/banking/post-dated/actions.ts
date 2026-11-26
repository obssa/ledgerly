"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Module 5: post-dated cheques (062). The API checks payments.receive or payments.pay, and every rule, again. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  return formStateFromError(error);
}

export async function recordPostDatedCheque(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.recordPostDatedCheque({
      direction: form.get("direction") === "out" ? "out" : "in", contact_id: text(form, "contact_id") ?? "", account_id: text(form, "account_id") ?? "",
      cheque_number: text(form, "cheque_number") ?? "", drawn_on: text(form, "drawn_on") ?? null, handed_on: text(form, "handed_on") ?? "",
      cheque_date: text(form, "cheque_date") ?? "", currency: text(form, "currency") ?? "", amount: (text(form, "amount") ?? "").replace(/[\s,]/g, ""),
      note: text(form, "note") ?? null,
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Held until its date. It is not in the books yet." };
}

export async function presentPostDatedCheque(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.presentPostDatedCheque(text(form, "id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Presented: recorded as a cheque payment today." };
}

export async function cancelPostDatedCheque(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.cancelPostDatedCheque(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Cancelled." };
}

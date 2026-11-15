"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { paymentBody } from "@/lib/payments";
import { getSession } from "@/lib/session";

/* Server Functions behind the pay-bills screens. The API checks who may pay (payments.pay) and reverse (payments.reverse). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Only an accountant, owner or admin can pay suppliers." };
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

export async function payBills(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  let cashWarning = false;
  try {
    const paid = await ledgerly.payBills(paymentBody(form, "bill"), ctx);
    id = paid.id;
    cashWarning = paid.warnings.some((w) => w.type === "cash_limit");
  } catch (error) {
    return refusal(error);
  }
  // A fixed flag, never text in the URL: the payment page shows the warning.
  redirect(`/payments-made/${encodeURIComponent(id)}${cashWarning ? "?cash=over" : ""}`);
}

export async function reversePaymentMade(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.reversePaymentMade(text(form, "id") ?? "", { date: text(form, "date") ?? "", reason: text(form, "reason") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Payment reversed. Its bills are open again." };
}

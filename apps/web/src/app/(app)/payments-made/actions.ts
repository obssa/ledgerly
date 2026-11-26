"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { settleProblem, settledIn } from "@/lib/payments";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { paymentBody } from "@/lib/payments";
import { batchBody } from "@/lib/batchPayments";
import { getSession } from "@/lib/session";

/* Server Functions behind the pay-bills screens. The API checks who may pay (payments.pay) and reverse (payments.reverse). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
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

/** Module 3: the pay run. One payment per supplier for the bills ticked; all are made or none. */
export async function payBatch(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let suppliers: Record<string, string> = {};
  try {
    suppliers = JSON.parse(text(form, "suppliers") ?? "{}") as Record<string, string>;
  } catch {
    return { error: "Reload the page and try again." };
  }
  const { bills, withholding } = batchBody(form, suppliers);
  if (bills.length === 0) return { error: "Tick at least one bill." };
  try {
    await ledgerly.payBatch({
      account_id: text(form, "account_id") ?? "", payment_date: text(form, "payment_date") ?? "", method: text(form, "method") ?? "",
      reference: text(form, "reference") || null, currency: text(form, "currency") ?? "", bills, withholding,
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect("/payments-made");
}

export async function payBills(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  let cashWarning = false;
  const settled = settledIn(form);
  const missing = settleProblem(settled, settled.length, form.get("keep_as_credit") === "on", "bill");
  if (missing) return { error: missing };
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

/** Applies an advance on this payment to one of the supplier's open bills. */
export async function allocatePaymentMade(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.allocatePaymentMade(text(form, "id") ?? "", { allocations: [{ bill_id: text(form, "document_id") ?? "", amount: (text(form, "amount") ?? "").replace(/,/g, "") }] }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Applied." };
}

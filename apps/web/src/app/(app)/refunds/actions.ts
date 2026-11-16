"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind refunds. The API checks who may pay a customer back (payments.pay) and record a supplier's refund (payments.receive). */

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

export async function recordRefund(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const side = form.get("side") === "supplier" ? "supplier" : "customer";
  const body: Record<string, unknown> = {
    contact_id: text(form, "contact_id") ?? "", account_id: text(form, "account_id") ?? "", payment_date: text(form, "payment_date") ?? "",
    method: text(form, "method") ?? "", currency: text(form, "currency") ?? "", amount: (text(form, "amount") ?? "").replace(/[,\s]/g, ""),
  };
  for (const name of ["reference", "credit_id", "payment_id"]) if (text(form, name)) body[name] = text(form, name);
  let id: string;
  try {
    id = (await ledgerly.recordRefund(side, body, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/refunds/${encodeURIComponent(id)}`);
}

export async function reverseRefund(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.reverseRefund(text(form, "id") ?? "", { date: text(form, "date") ?? "", reason: text(form, "reason") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Refund reversed. The credit is back." };
}

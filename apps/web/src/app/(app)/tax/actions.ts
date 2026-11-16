"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind the tax centre. The API checks who may file and settle returns (tax.file) and every rule. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot file tax returns." };
  return formStateFromError(error);
}

/** File the return for the period on screen: its figures are kept as they are, and the period is locked for that tax. */
export async function fileTaxReturn(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.fileTaxReturn({
      kind: text(form, "kind") ?? "", period_start: text(form, "period_start") ?? "", period_end: text(form, "period_end") ?? "",
      filed_on: text(form, "filed_on") ?? "", reference: text(form, "reference") ?? null,
    }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/tax/returns/${encodeURIComponent(id)}`);
}

/** Settle a filed return: what is due is paid from a bank, wallet or cash account. */
export async function settleTaxReturn(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.settleTaxReturn(text(form, "id") ?? "", { date: text(form, "date") ?? "", bank_account_id: text(form, "bank_account_id") ?? null }, ctx);
    refresh();
    return { notice: `Settled on ${done.settled_on}${done.settlement ? ` (entry #${done.settlement.number})` : ""}.` };
  } catch (error) {
    return refusal(error);
  }
}

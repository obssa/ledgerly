"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind banking. The API checks who may open accounts (banking.manage), transfer and deposit. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  return formStateFromError(error);
}

const amount = (v: string | undefined) => (v ?? "").replace(/[,\s]/g, "");
const DETAILS = ["bank_name", "account_number", "branch", "provider", "wallet_number"] as const;

function details(form: FormData): Record<string, string> | undefined {
  if (!DETAILS.some((d) => form.has(`bank_details.${d}`))) return undefined;
  const out: Record<string, string> = {};
  for (const d of DETAILS) {
    const v = text(form, `bank_details.${d}`);
    if (v) out[d] = v;
  }
  return out;
}

export async function createBankAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createBankAccount({ name: text(form, "name") ?? "", kind: text(form, "kind") ?? "bank",
      ...(text(form, "currency") ? { currency: text(form, "currency") } : {}), ...(details(form) ? { bank_details: details(form) } : {}) }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/banking/${encodeURIComponent(id)}`);
}

export async function saveBankAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.updateBankAccount(text(form, "id") ?? "", { ...(form.has("name") ? { name: text(form, "name") ?? "" } : {}),
      ...(form.has("is_active") ? { is_active: form.get("is_active") === "true" } : {}), ...(details(form) ? { bank_details: details(form) } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Saved." };
}

export async function transferMoney(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.transferMoney({ from_account_id: text(form, "from_account_id") ?? "", to_account_id: text(form, "to_account_id") ?? "",
      date: text(form, "date") ?? "", amount: amount(text(form, "amount")), ...(amount(text(form, "fee")) ? { fee: amount(text(form, "fee")) } : {}),
      ...(text(form, "memo") ? { memo: text(form, "memo") } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Transferred and posted." };
}

export async function depositReceipts(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const receipts = (text(form, "receipts") ?? "").split(/[\n;]+/).map((r) => amount(r)).filter((r) => r !== "");
  try {
    await ledgerly.depositReceipts({ bank_account_id: text(form, "bank_account_id") ?? "", date: text(form, "date") ?? "", receipts,
      ...(text(form, "slip_number") ? { slip_number: text(form, "slip_number") } : {}), ...(amount(text(form, "fee")) ? { fee: amount(text(form, "fee")) } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Deposited and posted." };
}

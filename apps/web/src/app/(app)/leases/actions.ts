"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind leases (095). The API checks who may set them up (assets.manage) and pay them (payments.pay). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown, what: string): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: `Your role cannot ${what}.` };
  return formStateFromError(error);
}

const amount = (v?: string | null) => (v ?? "").replace(/[,\s]/g, "");

export async function addLease(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const finance = text(form, "kind") === "finance";
  const body: Record<string, unknown> = {
    code: text(form, "code") ?? "", name: text(form, "name") ?? "", lessor_id: text(form, "lessor_id") ?? null, kind: finance ? "finance" : "operating",
    starts_on: text(form, "starts_on") ?? "", months: Number(text(form, "months") ?? "0"), payment: amount(text(form, "payment")), in_advance: form.get("in_advance") === "on",
  };
  if (finance) {
    const rate = Number(amount(text(form, "rate_percent")));
    Object.assign(body, {
      annual_rate: Number.isFinite(rate) ? (rate / 100).toFixed(6) : "", liability_account_id: text(form, "liability_account_id") ?? "",
      interest_account_id: text(form, "interest_account_id") ?? "", asset_account_id: text(form, "asset_account_id") ?? "",
      depreciation_account_id: text(form, "depreciation_account_id") ?? "", depreciation_expense_account_id: text(form, "depreciation_expense_account_id") ?? "",
      ...(text(form, "useful_life_months") ? { useful_life_months: Number(text(form, "useful_life_months")) } : {}),
    });
  } else {
    body.expense_account_id = text(form, "expense_account_id") ?? "";
  }
  let id: string;
  try {
    id = (await ledgerly.addLease(body, ctx)).id;
  } catch (error) {
    return refusal(error, "set up leases");
  }
  redirect(`/leases/${encodeURIComponent(id)}`);
}

export async function payLease(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const l = await ledgerly.payLease(text(form, "id") ?? "", { date: text(form, "date") ?? "", bank_account_id: text(form, "bank_account_id") ?? "" }, ctx);
    refresh();
    const done = l.schedule.filter((p) => p.paid_on).length;
    return { notice: `Payment ${done} of ${l.months} posted.` };
  } catch (error) {
    return refusal(error, "pay leases");
  }
}

export async function endLease(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.endLease(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: "The lease has ended: it takes no more payments." };
  } catch (error) {
    return refusal(error, "end leases");
  }
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { scheduleBills } from "@/lib/scheduledPayments";
import { getSession } from "@/lib/session";

/* Server Functions behind scheduled payments (103). The API checks who may plan and confirm them (payments.pay). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Only an accountant, owner or admin can schedule and confirm supplier payments." };
  if (error instanceof ApiError && error.status === 404) return { error: "This scheduled payment is not found: reload the page." };
  return formStateFromError(error);
}

const amount = (form: FormData, name: string) => text(form, name)?.replace(/,/g, "") ?? null;
const id = (form: FormData) => {
  const v = text(form, "id") ?? "";
  return /^[0-9a-f-]{36}$/i.test(v) ? v : "";
};

function planBody(form: FormData) {
  return {
    account_id: text(form, "account_id") ?? "", pay_on: text(form, "pay_on") ?? "", method: text(form, "method") ?? "",
    reference: text(form, "reference") ?? null, note: text(form, "note") ?? null, bills: scheduleBills(form),
  };
}

export async function schedulePayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body = planBody(form);
  if (body.bills.length === 0) return { error: "Tick at least one bill to pay." };
  let created: string;
  try {
    created = (await ledgerly.schedulePayment({ ...body, contact_id: text(form, "contact_id") ?? "", currency: text(form, "currency") ?? "" }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/payments-made/scheduled/${encodeURIComponent(created)}?saved=1`);
}

export async function updateScheduledPayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const plan = id(form);
  const body = planBody(form);
  if (body.bills.length === 0) return { error: "Tick at least one bill to pay, or cancel the scheduled payment." };
  try {
    await ledgerly.updateScheduledPayment(plan, body, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/payments-made/scheduled/${encodeURIComponent(plan)}?saved=1`);
}

/** The money went out: the payment made is recorded and the bills are paid. */
export async function confirmScheduledPayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let payment: string;
  try {
    payment = (await ledgerly.confirmScheduledPayment(id(form), {
      payment_date: text(form, "payment_date") ?? null, reference: text(form, "reference") ?? null, fx_rate: amount(form, "fx_rate"),
      fee: amount(form, "fee"), withholding: amount(form, "withholding"), withholding_certificate: text(form, "withholding_certificate") ?? null,
      cheque_number: text(form, "cheque_number") ?? null,
    }, ctx)).payment_id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/payments-made/${encodeURIComponent(payment)}`);
}

export async function cancelScheduledPayment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.cancelScheduledPayment(id(form), text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Cancelled. Nothing was recorded in the books; its bills are free to pay or schedule again." };
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind purchase requisitions (084). The API checks who may ask, decide and order. */

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

const amount = (v: string | undefined) => (v ?? "").replace(/[,\s]/g, "");

/** Rows `desc.N`, `qty.N`, `price.N`; a row without a description is left out. */
export async function requestPurchase(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const lines: Record<string, string>[] = [];
  for (let i = 0; i < 30; i++) {
    const description = text(form, `desc.${i}`);
    if (!description) continue;
    const price = amount(text(form, `price.${i}`));
    lines.push({ description, quantity: amount(text(form, `qty.${i}`)) || "1", ...(price ? { estimated_price: price } : {}) });
  }
  if (lines.length === 0) return { error: "Say what is to be bought." };
  let id: string;
  try {
    id = (await ledgerly.createRequisition({ reason: text(form, "reason") ?? "", needed_by: text(form, "needed_by") || null, lines }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/purchase-requisitions/${encodeURIComponent(id)}`);
}

export async function decideRequisition(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const approve = form.get("decision") === "approve";
  try {
    await ledgerly.decideRequisition(text(form, "id") ?? "", approve ? "approve" : "reject", text(form, "note") || null, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: approve ? "Approved." : "Rejected." };
}

export async function withdrawRequisition(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.withdrawRequisition(text(form, "id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Withdrawn." };
}

/** Turns an approved requisition into a draft purchase order, and opens it. */
export async function orderRequisition(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const count = Number(text(form, "lines") ?? "0");
  const prices = Array.from({ length: count }, (_, i) => amount(text(form, `price.${i}`)) || null);
  let orderId: string | null;
  try {
    orderId = (await ledgerly.orderRequisition(text(form, "id") ?? "", {
      contact_id: text(form, "contact_id") ?? "", account_id: text(form, "account_id") ?? "", prices,
    }, ctx)).purchase_order_id;
  } catch (error) {
    return refusal(error);
  }
  redirect(orderId ? `/purchase-orders/${encodeURIComponent(orderId)}` : "/purchase-requisitions");
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { deliveries } from "@/lib/orders";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the purchase order screens (068). They are purchase documents: the API checks bills.create
 * on every change and recalculates every amount. Nothing here posts; the bills made from an order do, when approved.
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

const pageOf = (id: string) => `/purchase-orders/${encodeURIComponent(id)}`;

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  if (error instanceof ApiError && error.status === 409) {
    return { error: "Someone else changed this after you opened it. Open it again to see their changes, then make yours." };
  }
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/** Saves a purchase order (new, or the one being edited); with intent=accept it is also issued. Its page opens either way. */
export async function savePurchaseOrder(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const existing = text(form, "id");
  let id: string;
  try {
    const body = invoiceBody(form);
    const version = Number(text(form, "lock_version"));
    const reason = text(form, "reason");
    id = existing
      ? (await ledgerly.updatePurchaseOrder(existing, { ...body, ...(Number.isInteger(version) && version > 0 ? { lock_version: version } : {}), ...(reason ? { reason } : {}) }, ctx)).id
      : (await ledgerly.createPurchaseOrder(body, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  if (form.get("intent") === "accept") {
    try {
      await ledgerly.purchaseOrderStep(id, "issue", {}, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
      redirect(`${pageOf(id)}?saved=1`);   // saved; the page shows why it could not be issued
    }
  }
  redirect(pageOf(id));
}

/** Issue, cancel or close, from the order's page. */
export async function purchaseOrderStep(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const step = form.get("step");
  const NOTICE: Record<string, string> = { issue: "Issued.", cancel: "Cancelled.", close: "Closed: what was left is no longer on order." };
  if (typeof step !== "string" || !(step in NOTICE)) return { error: "Unknown action." };
  try {
    const note = text(form, "note");
    await ledgerly.purchaseOrderStep(text(form, "id") ?? "", step as "issue" | "cancel" | "close", note ? { note } : {}, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: NOTICE[step] };
}

/** Records the goods that arrived (receive_<line id>) on a goods received note, and opens it (069). */
export async function receiveGoods(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let made: { id: string };
  try {
    const reference = text(form, "reference");
    const note = text(form, "note");
    made = await ledgerly.receivePurchaseOrder(text(form, "id") ?? "", {
      received_on: text(form, "received_on") ?? "", lines: deliveries(form, "receive") ?? [],
      ...(reference ? { reference } : {}), ...(note ? { note } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/goods-received/${encodeURIComponent(made.id)}`);
}

/** Voids a goods received note: its goods go out again and its entry is reversed (069). */
export async function voidGoodsReceipt(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const reason = text(form, "reason");
    await ledgerly.voidGoodsReceipt(text(form, "id") ?? "", reason ? { reason } : {}, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Voided: its goods went out of stock again and its entry was reversed." };
}

/** Makes the draft bill for the quantities typed (deliver_<line id>), or for all that is left, and opens it. */
export async function billPurchaseOrder(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let made: { id: string };
  try {
    made = await ledgerly.billPurchaseOrder(text(form, "id") ?? "", deliveries(form), ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/bills/${encodeURIComponent(made.id)}`);
}

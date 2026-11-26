"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly, type QuoteKind } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { deliveries } from "@/lib/orders";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the quote and sales order screens (056). They are sales documents: the API checks
 * invoices.create on every change and recalculates every amount. Nothing here posts.
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

const kindOf = (form: FormData): QuoteKind => (form.get("doc_kind") === "sales_order" ? "sales_order" : "quote");
const pageOf = (kind: QuoteKind, id: string) => `/${kind === "quote" ? "quotes" : "sales-orders"}/${encodeURIComponent(id)}`;

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) {
    return { error: (error.body as { type?: string } | null)?.type === "DemoAccount" ? "Not available in the demo: nothing is emailed from the shared demo accounts." : "Your role cannot do this." };
  }
  if (error instanceof ApiError && error.status === 409) {
    return { error: "Someone else changed this after you opened it. Open it again to see their changes, then make yours." };
  }
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/**
 * Saves a quote or a sales order (a new one, or the one being edited, with the version it was read at). With
 * intent=send a quote is also marked as sent, with intent=accept an order is confirmed. Its page opens either way.
 */
export async function saveQuote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const kind = kindOf(form);
  const existing = text(form, "id");
  let id: string;
  try {
    const body = invoiceBody(form);
    const version = Number(text(form, "lock_version"));
    const reason = text(form, "reason");
    id = existing
      ? (await ledgerly.updateQuote(kind, existing, { ...body, ...(Number.isInteger(version) && version > 0 ? { lock_version: version } : {}), ...(reason ? { reason } : {}) }, ctx)).id
      : (await ledgerly.createQuote(kind, body, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  const intent = form.get("intent");
  if (intent === "send" || intent === "accept") {
    try {
      await ledgerly.quoteStep(kind, id, intent, {}, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
      redirect(`${pageOf(kind, id)}?saved=1`);   // saved; the page shows what is left to do
    }
  }
  redirect(pageOf(kind, id));
}

/** Send, accept or confirm, decline, extend or cancel, from the document's page. */
export async function quoteStep(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const kind = kindOf(form);
  const id = text(form, "id") ?? "";
  const step = form.get("step");
  const note = text(form, "note");
  const NOTICE: Record<string, string> = {
    send: "Marked as sent.", accept: kind === "quote" ? "Accepted." : "Confirmed.", decline: "Declined.", extend: "Offered again.", cancel: "Cancelled.",
    ...(kind === "sales_order" ? { close: "Closed: what was left is no longer owed." } : {}),
  };
  if (typeof step !== "string" || !(step in NOTICE)) return { error: "Unknown action." };
  try {
    const body = step === "extend" ? { valid_until: text(form, "valid_until") ?? "" } : note ? { note } : {};
    await ledgerly.quoteStep(kind, id, step as "send" | "accept" | "decline" | "extend" | "cancel" | "close", body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: NOTICE[step] };
}

export async function emailQuote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const { sent_to } = await ledgerly.emailQuote(kindOf(form), text(form, "id") ?? "", text(form, "note") ?? undefined, ctx);
    refresh();
    return { notice: `Sent to ${sent_to}.` };
  } catch (error) {
    return refusal(error);
  }
}

/** Records the goods that left (receive_<line id>, the delivery fields of the note form) on a delivery note, and opens it (071). */
export async function deliverGoods(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let made: { id: string };
  try {
    const reference = text(form, "reference");
    const note = text(form, "note");
    made = await ledgerly.deliverSalesOrder(text(form, "id") ?? "", {
      delivered_on: text(form, "delivered_on") ?? "", lines: deliveries(form, "receive") ?? [],
      ...(reference ? { reference } : {}), ...(note ? { note } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/delivery-notes/${encodeURIComponent(made.id)}`);
}

/** Voids a delivery note: its goods come back into stock and its entry is reversed (071). */
export async function voidDeliveryNote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const reason = text(form, "reason");
    await ledgerly.voidDeliveryNote(text(form, "id") ?? "", reason ? { reason } : {}, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Voided: its goods are back in stock and its entry was reversed." };
}

/** Makes the sales order or the draft invoice, and opens it. */
export async function convertQuote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const kind = kindOf(form);
  const to = form.get("to") === "sales_order" ? "sales_order" : "invoice";
  // 067: an order's quantities to deliver now, one field per open line (deliver_<line id>).
  const lines = kind === "sales_order" ? deliveries(form) : undefined;
  let made: { id: string; type: string };
  try {
    made = await ledgerly.convertQuote(kind, text(form, "id") ?? "", to, text(form, "number") ?? undefined, ctx, lines);
  } catch (error) {
    return refusal(error);
  }
  redirect(made.type === "invoice" ? `/invoices/${encodeURIComponent(made.id)}` : pageOf("sales_order", made.id));
}

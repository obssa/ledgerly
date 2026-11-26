"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the bill screens. The API recalculates every amount and decides who may save (bills.create),
 * approve (bills.approve) and void (transactions.edit).
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot do this." };
  if (error instanceof ApiError && error.status === 409) {
    return { error: "Someone else changed this bill after you opened it. Open it again to see their changes, then make yours." };
  }
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/**
 * Saves a new bill, the draft being edited (with the version it was read at), or corrects a posted bill with a
 * reason (intent=correct: the API reverses and reposts it and keeps the old version).
 */
export async function createBill(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const existing = text(form, "id");
  const correcting = form.get("intent") === "correct";
  let id: string;
  try {
    const body = invoiceBody(form);
    const version = Number(text(form, "lock_version"));
    const reason = text(form, "reason");
    if (existing && correcting && (!reason || reason.trim().length < 3)) {
      return { error: "Say why it is being changed: the reason is kept in the history.", fields: { reason: ["Required"] } };
    }
    id = existing
      ? (await ledgerly.updateBill(existing, { ...body, ...(Number.isInteger(version) && version > 0 ? { lock_version: version } : {}), ...(correcting ? { reason } : {}) }, ctx)).id
      : (await ledgerly.createBill(body, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  if (correcting) redirect(`/bills/${encodeURIComponent(id)}?corrected=1`);
  if (form.get("intent") === "approve") {
    try {
      await ledgerly.approveBill(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
      redirect(`/bills/${encodeURIComponent(id)}?posted=0`); // saved as a draft; the bill page says so
    }
  }
  redirect(`/bills/${encodeURIComponent(id)}`);
}

export async function billAction(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  const action = form.get("action");
  try {
    if (action === "approve") await ledgerly.approveBill(id, ctx);
    else if (action === "submit") await ledgerly.submitBill(id, ctx);
    else if (action === "void") await ledgerly.voidBill(id, text(form, "reason") ?? "", ctx);
    else return { error: "Unknown action." };
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: action === "approve" ? "Posted." : action === "submit" ? "Sent for approval." : "Voided. It no longer counts in the books; the number is kept." };
}

/** A draft supplier credit for a posted bill; it opens on its own page, where it is approved like a bill. */
export async function createSupplierCredit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const billId = text(form, "invoice_id") ?? "";
  const body = invoiceBody(form);
  let id: string;
  try {
    id = (await ledgerly.createSupplierCredit(billId, { issue_date: body.issue_date, notes: body.notes, reference: body.reference, lines: body.lines, restock: form.get("restock") === "1" }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/bills/${encodeURIComponent(id)}`);
}

export async function applySupplierCredit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.applySupplierCredit(text(form, "credit_note_id") ?? "", { bill_id: text(form, "invoice_id") ?? "", amount: (text(form, "amount") ?? "").replace(/,/g, "") }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Credit applied." };
}

/** Module 4: a freight, duty or clearing bill added to the cost of the goods another bill brought in (060). */
export async function applyLandedCost(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const amount = (text(form, "amount") ?? "").replace(/[\s,]/g, "");
  let done: { amount: string; inventory: string; cost_of_sales: string };
  try {
    done = await ledgerly.applyLandedCost(text(form, "id") ?? "", {
      goods_bill_id: text(form, "goods_bill_id") ?? "", method: form.get("method") === "quantity" ? "quantity" : "value",
      ...(amount ? { amount } : {}), ...(text(form, "date") ? { date: text(form, "date") } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `${done.amount} added: ${done.inventory} onto the goods on hand, ${done.cost_of_sales} to cost of goods sold.` };
}

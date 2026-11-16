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

/** Saves the draft; with intent=approve also posts it. Either way the bill page opens. */
export async function createBill(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createBill(invoiceBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
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
  return { notice: action === "approve" ? "Posted." : action === "submit" ? "Sent for approval." : "Voided. The number is kept and the entry reversed." };
}

/** A draft supplier credit for a posted bill; it opens on its own page, where it is approved like a bill. */
export async function createSupplierCredit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const billId = text(form, "invoice_id") ?? "";
  const body = invoiceBody(form);
  let id: string;
  try {
    id = (await ledgerly.createSupplierCredit(billId, { issue_date: body.issue_date, notes: body.notes, reference: body.reference, lines: body.lines }, ctx)).id;
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

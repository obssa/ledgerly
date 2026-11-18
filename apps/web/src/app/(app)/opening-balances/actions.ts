"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { openingLines } from "@/lib/opening";
import { getSession } from "@/lib/session";

/** Enters (or replaces) the opening balances. The API reverses the previous opening entry and posts the new one. */
export async function saveOpeningBalances(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");

  let difference = "0.00";
  try {
    difference = (await ledgerly.saveOpeningBalances(openingLines(form), { token, companyId })).difference;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot enter opening balances. Ask an accountant, owner or admin." };
    return formStateFromError(error);
  }
  refresh();
  return {
    notice: difference === "0.00"
      ? "Opening balances posted. They balance."
      : `Opening balances posted. The difference of ${difference} went to Opening balance equity: check it before closing the first period.`,
  };
}

/**
 * An open invoice or bill from the old books (057): what a customer still owed, or what was still owed to a supplier,
 * on the conversion date. It posts on that date against opening balance equity and is paid like any other.
 */
export async function enterOpeningDocument(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  const type = form.get("type") === "bill" ? "bill" : "invoice";
  const amount = (text(form, "amount") ?? "").replace(/[\s,]/g, "");
  let number: string;
  try {
    number = (await ledgerly.enterOpeningDocument(type, {
      contact_id: text(form, type === "bill" ? "supplier_id" : "customer_id") ?? "",
      ...(text(form, "number") ? { number: text(form, "number") } : {}),
      ...(type === "bill" ? { reference: text(form, "reference") ?? "" } : {}),
      issue_date: text(form, "issue_date") ?? "",
      due_date: text(form, "due_date") ?? null,
      currency: text(form, "currency") ?? "",
      amount,
    }, { token, companyId })).number;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot enter opening balances. Ask an accountant, owner or admin." };
    const state = formStateFromError(error);
    // The supplier or customer field the API names as contact_id.
    if (state.fields?.contact_id) state.fields = { ...state.fields, [type === "bill" ? "supplier_id" : "customer_id"]: state.fields.contact_id };
    return state;
  }
  refresh();
  return { notice: `${type === "bill" ? "Bill" : "Invoice"} ${number} brought in. It is owed from the conversion date and paid like any other.` };
}

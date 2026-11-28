"use server";

import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { customerBody } from "@/lib/customers";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { customerOption, purchaseItemOption, saleItemOption, supplierOption, type ContactOption, type ItemOption } from "@/lib/options";
import { getSession } from "@/lib/session";
import { supplierBody } from "@/lib/suppliers";

/*
 * QuickBooks' "+ Add new" on a document form: a customer, supplier or product or service added without leaving the
 * form. The same API calls as the full screens, so the API checks who may add one (contacts.create,
 * inventory.items), validates it and records it in the audit log. What comes back is the new entry in the form's
 * own shape, which the form adds to its list and chooses.
 */

export type Added<T> = FormState & { added?: T };

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown, what: string): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: `Your role cannot add ${what}. Ask an accountant, owner or admin.` };
  return formStateFromError(error);
}

/** A new customer (on a sales form) or supplier (on a purchase form). */
export async function quickAddContact(side: "customer" | "supplier", form: FormData): Promise<Added<ContactOption>> {
  const ctx = await context();
  try {
    if (side === "customer") return { added: customerOption(await ledgerly.createCustomer(customerBody(form), ctx)) };
    return { added: supplierOption(await ledgerly.createSupplier(supplierBody(form), ctx)) };
  } catch (error) {
    return refusal(error, side === "customer" ? "customers" : "suppliers");
  }
}

const TYPES = ["service", "non_inventory", "inventory"] as const;
const amount = (v: string | undefined) => (v ?? "").replace(/[,\s]/g, "");

/**
 * A new product or service. On a sales form it carries its sale price and income account, on a purchase form its
 * cost and expense account, as QuickBooks' quick add does; the rest (units, reorder levels, bundles) on its own page.
 */
export async function quickAddItem(side: "sale" | "purchase", form: FormData): Promise<Added<ItemOption>> {
  const ctx = await context();
  const type = text(form, "type") ?? "service";
  const body: Record<string, unknown> = {
    name: text(form, "name") ?? "",
    type: (TYPES as readonly string[]).includes(type) ? type : "service",
    sku: text(form, "sku") ?? null,
  };
  const price = amount(text(form, "price"));
  const account = text(form, "account_id");
  if (side === "sale") {
    body.sale_price = price || null;
    if (account) body.income_account_id = account;
  } else {
    body.purchase_price = price || null;
    if (account) body.expense_account_id = account;
  }
  try {
    const item = await ledgerly.createItem(body, ctx);
    return { added: side === "sale" ? saleItemOption(item) : purchaseItemOption(item) };
  } catch (error) {
    const state = refusal(error, "products or services");
    // The API names the price and the account by their own fields; the dialog has one of each.
    if (state.fields) {
      const fields = { ...state.fields };
      for (const [from, to] of [["sale_price", "price"], ["purchase_price", "price"], ["income_account_id", "account_id"], ["expense_account_id", "account_id"]]) {
        if (fields[from]) fields[to] = fields[from];
      }
      return { ...state, fields };
    }
    return state;
  }
}

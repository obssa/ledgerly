"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind items and stock. The API checks who may change items (inventory.items) and adjust stock (inventory.adjust). */

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

function itemBody(form: FormData): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  for (const name of ["name", "sku", "type", "unit"]) if (form.has(name)) body[name] = text(form, name) ?? (name === "sku" ? null : "");
  for (const name of ["sale_price", "purchase_price", "reorder_point"]) if (form.has(name)) body[name] = amount(text(form, name)) || null;
  return body;
}

export async function createItem(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createItem(itemBody(form), ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/products/${encodeURIComponent(id)}`);
}

export async function saveItem(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.updateItem(text(form, "id") ?? "", { ...itemBody(form), ...(form.has("is_active") ? { is_active: form.get("is_active") === "true" } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Saved." };
}

export async function adjustStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: Record<string, unknown> = {
    item_id: text(form, "item_id") ?? "", warehouse_id: text(form, "warehouse_id") ?? "", date: text(form, "date") ?? "",
    quantity: amount(text(form, "quantity")), reason: text(form, "reason") ?? "", opening: form.get("opening") === "on",
  };
  if (amount(text(form, "unit_cost")) !== "") body.unit_cost = amount(text(form, "unit_cost"));
  let result: { amount: string };
  try {
    result = await ledgerly.adjustStock(body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Recorded. Value ${result.amount}, posted to the ledger.` };
}

export async function createWarehouse(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.createWarehouse(text(form, "name") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Warehouse added." };
}

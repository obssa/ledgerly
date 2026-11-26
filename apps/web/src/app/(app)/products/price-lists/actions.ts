"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind price lists (080). The API checks who may change them (inventory.items). */

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

export async function createPriceList(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createPriceList({ name: text(form, "name") ?? "", currency: text(form, "currency") || undefined, notes: text(form, "notes") || null }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/products/price-lists/${encodeURIComponent(id)}`);
}

export async function savePriceList(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.updatePriceList(text(form, "id") ?? "", {
      name: text(form, "name") ?? "", currency: text(form, "currency") ?? "", notes: text(form, "notes") || null, is_active: form.get("is_active") === "on",
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Saved." };
}

/** Every item with a price typed is on the list at that price; an item left empty is taken off it. */
export async function savePrices(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const prices: { item_id: string; unit_price: string }[] = [];
  for (const [name, value] of form.entries()) {
    if (!name.startsWith("price.") || typeof value !== "string") continue;
    const price = value.replace(/[,\s]/g, "");
    if (price !== "") prices.push({ item_id: name.slice("price.".length), unit_price: price });
  }
  try {
    await ledgerly.setPriceListPrices(text(form, "id") ?? "", prices, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `${prices.length} price${prices.length === 1 ? "" : "s"} saved.` };
}

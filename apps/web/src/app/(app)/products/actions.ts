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
  for (const name of ["name", "sku", "type", "unit", "costing", "tracking"]) if (form.has(name)) body[name] = text(form, name) ?? (name === "sku" ? null : "");
  if (form.has("barcode")) body.barcode = text(form, "barcode") || null;   // 085
  for (const name of ["sale_price", "purchase_price", "reorder_point", "max_level"]) if (form.has(name)) body[name] = amount(text(form, name)) || null;
  if (form.has("preferred_supplier_id")) body.preferred_supplier_id = text(form, "preferred_supplier_id") || null;   // 075
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

/** 073: a bundle's parts, the whole list at once (part_item_<n> and part_qty_<n>; an empty item is skipped). */
export async function setBundleParts(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const parts: { item_id: string; quantity: string }[] = [];
  for (const [key, value] of form.entries()) {
    const m = /^part_item_(\d{1,2})$/.exec(key);
    if (m && typeof value === "string" && value.trim() !== "") parts.push({ item_id: value.trim(), quantity: amount(text(form, `part_qty_${m[1]}`)) });
  }
  try {
    await ledgerly.setItemComponents(text(form, "id") ?? "", parts, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Parts saved." };
}

/** 090: where the item is kept in a warehouse; empty clears it. */
export async function setBinLocation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const bin = text(form, "bin") ?? "";
  try {
    await ledgerly.setBinLocation(text(form, "id") ?? "", { warehouse_id: text(form, "warehouse_id") ?? "", bin: bin || null }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: bin ? `Kept in ${bin}.` : "Bin cleared." };
}

/** 089: the item's variants, from up to three options with values separated by commas. */
export async function makeVariants(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const options = [0, 1, 2].map((i) => ({ name: text(form, `option_name_${i}`) ?? "", values: (text(form, `option_values_${i}`) ?? "").split(",").map((v) => v.trim()).filter(Boolean) }))
    .filter((o) => o.name !== "" && o.values.length > 0);
  if (options.length === 0) return { error: "Give at least one option and its values, such as Size: S, M, L." };
  let made: number;
  try {
    made = (await ledgerly.makeVariants(text(form, "id") ?? "", options, ctx)).made.length;
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: made === 0 ? "Those variants are made already." : `${made} variant${made === 1 ? "" : "s"} made.` };
}

/** 087: an assembly's parts, how many of each go into one. */
export async function setAssemblyParts(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const parts: { item_id: string; quantity: string }[] = [];
  for (const [key, value] of form.entries()) {
    const m = /^part_item_(\d{1,2})$/.exec(key);
    if (m && typeof value === "string" && value.trim() !== "") parts.push({ item_id: value.trim(), quantity: amount(text(form, `part_qty_${m[1]}`)) });
  }
  try {
    await ledgerly.setAssemblyParts(text(form, "id") ?? "", parts, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Parts saved." };
}

/** 087: builds some of the assembly from its parts, at their cost. */
export async function buildAssembly(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let value: string;
  try {
    value = (await ledgerly.buildAssembly(text(form, "id") ?? "", {
      warehouse_id: text(form, "warehouse_id") ?? "", quantity: amount(text(form, "quantity")), built_on: text(form, "built_on") ?? "", reference: text(form, "reference") || null,
    }, ctx)).value;
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Built, at a cost of ${value}.` };
}

/** 072: another unit the item is bought or sold in (a carton of 12); its name and size never change. */
export async function addItemUnit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.addItemUnit(text(form, "id") ?? "", { name: text(form, "name") ?? "", factor: amount(text(form, "factor")), barcode: text(form, "barcode") || null }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Unit added." };
}

/** 072: a unit no longer offered on new lines; documents already in it keep it. */
export async function archiveItemUnit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.archiveItemUnit(text(form, "id") ?? "", text(form, "unit_id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Unit archived." };
}

/** 075: draft purchase orders for the items ticked (order_<item id>), at the quantities typed (qty_<item id>), one per supplier. */
export async function orderLowStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const lines: { item_id: string; quantity: string }[] = [];
  for (const [key, value] of form.entries()) {
    const m = /^order_([0-9a-f-]{36})$/.exec(key);
    if (m && value === "on") lines.push({ item_id: m[1], quantity: amount(text(form, `qty_${m[1]}`)) });
  }
  if (lines.length === 0) return { error: "Tick the items to order." };
  let made: { id: string }[];
  try {
    made = (await ledgerly.orderLowStock({ warehouse_id: text(form, "warehouse_id") ?? "", lines }, ctx)).data;
  } catch (error) {
    return refusal(error);
  }
  redirect(made.length === 1 ? `/purchase-orders/${encodeURIComponent(made[0].id)}` : "/purchase-orders");
}

export async function adjustStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: Record<string, unknown> = {
    item_id: text(form, "item_id") ?? "", warehouse_id: text(form, "warehouse_id") ?? "", date: text(form, "date") ?? "",
    quantity: amount(text(form, "quantity")), reason: text(form, "reason") ?? "", opening: form.get("opening") === "on",
  };
  if (amount(text(form, "unit_cost")) !== "") body.unit_cost = amount(text(form, "unit_cost"));
  // 074: the batch of an item tracked by batch (coming in, or taken out).
  if (text(form, "batch")) body.batch = text(form, "batch");
  if (text(form, "batch") && text(form, "expires_on")) body.expires_on = text(form, "expires_on");
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

export async function transferStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let value: string;
  try {
    value = (await ledgerly.transferStock({
      item_id: text(form, "item_id") ?? "", from_warehouse_id: text(form, "from_warehouse_id") ?? "", to_warehouse_id: text(form, "to_warehouse_id") ?? "",
      date: text(form, "date") ?? "", quantity: amount(text(form, "quantity")), ...(text(form, "reference") ? { reference: text(form, "reference") } : {}),
    }, ctx)).value;
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Moved, at a cost of ${value}.` };
}

/** Module 9a: materials issued from a warehouse to a project, at cost, charged to the cost account chosen. */
export async function issueStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let result: { amount: string };
  try {
    result = await ledgerly.issueStock({
      item_id: text(form, "item_id") ?? "", warehouse_id: text(form, "warehouse_id") ?? "", project_id: text(form, "project_id") ?? "",
      account_id: text(form, "account_id") ?? "", date: text(form, "date") ?? "", quantity: amount(text(form, "quantity")),
      ...(text(form, "note") ? { note: text(form, "note") } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: `Issued. Cost ${result.amount}, charged to the project.` };
}

/** Module 4: a stock count of one warehouse; only the lines filled in are counted. */
export async function countStock(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const counted: { item_id: string; quantity: string }[] = [];
  for (const [key, value] of form.entries()) {
    if (typeof value === "string" && key.startsWith("count_") && value.trim() !== "") counted.push({ item_id: key.slice(6), quantity: amount(value) });
  }
  if (counted.length === 0) return { error: "Enter the quantity counted for at least one item." };
  let result: { differences: unknown[]; net: string };
  try {
    result = await ledgerly.countStock({ warehouse_id: text(form, "warehouse_id") ?? "", date: text(form, "date") ?? "", counted,
      ...(text(form, "note") ? { note: text(form, "note") } : {}), ...(text(form, "bin_id") ? { bin_id: text(form, "bin_id") } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: result.differences.length === 0 ? "Counted: everything agrees with the books." : `Counted: ${result.differences.length} item${result.differences.length === 1 ? "" : "s"} adjusted, ${result.net} net, posted.` };
}

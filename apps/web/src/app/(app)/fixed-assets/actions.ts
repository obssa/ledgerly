"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { assetBody } from "@/lib/assets";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Functions behind the fixed asset register. The API checks who may register assets (assets.manage) and post
   depreciation (assets.depreciate), and every rule. */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown, what: string): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: `Your role cannot ${what}.` };
  return formStateFromError(error);
}

export async function saveAsset(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? null;
  let saved: string;
  try {
    saved = (await ledgerly.saveFixedAsset(id, assetBody(form), ctx)).id;
  } catch (error) {
    return refusal(error, "register assets");
  }
  if (id) {
    refresh();
    return { notice: "Saved." };
  }
  redirect(`/fixed-assets/${encodeURIComponent(saved)}`);
}

/** Sell or scrap an asset: its gain or loss is posted and it leaves the register. */
export async function disposeAsset(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.disposeFixedAsset(text(form, "id") ?? "", {
      date: text(form, "date") ?? "", proceeds: (text(form, "proceeds") ?? "").replace(/[,\s]/g, "") || null,
      bank_account_id: text(form, "bank_account_id") ?? null, reason: text(form, "reason") ?? null,
    }, ctx);
    refresh();
    return { notice: "Disposed of: the entry is posted and the asset is off the register." };
  } catch (error) {
    return refusal(error, "dispose of assets");
  }
}

/** 092: revalues the asset to its fair value; the surplus is kept on an equity account. */
export async function revalueAsset(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.revalueFixedAsset(text(form, "id") ?? "", {
      date: text(form, "date") ?? "", fair_value: (text(form, "fair_value") ?? "").replace(/[,\s]/g, ""),
      surplus_account_id: text(form, "surplus_account_id") ?? "", reason: text(form, "reason") ?? "",
    }, ctx);
    refresh();
    return { notice: "Revalued: the entry is posted, and later depreciation spreads the revalued amount over the remaining life." };
  } catch (error) {
    return refusal(error, "revalue assets");
  }
}

/** 091: writes the asset down when it is worth less than its carrying amount. */
export async function impairAsset(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.impairFixedAsset(text(form, "id") ?? "", {
      date: text(form, "date") ?? "", amount: (text(form, "amount") ?? "").replace(/[,\s]/g, ""), reason: text(form, "reason") ?? "",
    }, ctx);
    refresh();
    return { notice: "Written down: the entry is posted, and later depreciation spreads what is left over the remaining life." };
  } catch (error) {
    return refusal(error, "write assets down");
  }
}

export async function runDepreciation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.runDepreciation(text(form, "period_end") ?? "", ctx);
    refresh();
    return { notice: `Depreciation of ${done.total} posted for ${done.assets} asset${done.assets === 1 ? "" : "s"} (entry #${done.entry.number}).` };
  } catch (error) {
    return refusal(error, "post depreciation");
  }
}

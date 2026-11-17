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
  if (!companyId) redirect("/onboarding");
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

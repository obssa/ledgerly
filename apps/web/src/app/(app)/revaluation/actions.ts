"use server";

import { companyDate } from "@/lib/data";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* The Server Function behind the revaluation page. The API checks who may revalue (accounting.revalue) and every rule. */

export async function postRevaluation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  const rates: Record<string, string> = {};
  for (const [key, value] of form.entries()) {
    if (key.startsWith("rate:") && typeof value === "string" && value.trim() !== "") rates[key.slice(5)] = value.trim().replace(/[,\s]/g, "");
  }
  let posted: { net: string; entry: { number: number }; reversal: { date: string } };
  try {
    posted = await ledgerly.postRevaluation({ as_of: text(form, "as_of") ?? "", rates }, { token, companyId });
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot revalue currencies." };
    return formStateFromError(error);
  }
  refresh();
  return { notice: `Posted as entry #${posted.entry.number}, net ${posted.net}; reversed on ${await companyDate(posted.reversal.date)}.` };
}

"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, type FormState } from "@/lib/forms";
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

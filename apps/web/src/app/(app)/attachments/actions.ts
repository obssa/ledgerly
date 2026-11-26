"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/** Takes a file off its document (063); the API checks the document's permission again. */
export async function removeAttachment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  try {
    await ledgerly.removeAttachment(text(form, "id") ?? "", { token, companyId });
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot remove it." };
    return formStateFromError(error);
  }
  refresh();
  return { notice: "Removed." };
}

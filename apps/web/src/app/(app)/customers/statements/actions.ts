"use server";

import { redirect } from "next/navigation";
import { ApiError, ledgerly, type StatementType } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/** Each customer's statement by email: the API sends one to each address on file and says who has none. */
export async function emailStatements(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  const ids = form.getAll("contact_ids").filter((v): v is string => typeof v === "string" && /^[0-9a-f-]{36}$/i.test(v)).slice(0, 100);
  const type = (["balance_forward", "open_item", "transaction"] as const).find((t) => t === text(form, "type")) as StatementType | undefined;
  const date = (name: string) => (/^\d{4}-\d{2}-\d{2}$/.test(text(form, name) ?? "") ? text(form, name) : undefined);
  try {
    const r = await ledgerly.emailStatements({ contact_ids: ids, type, from: date("from"), to: date("to"), note: text(form, "note") || undefined }, { token, companyId });
    const none = r.skipped.filter((s) => s.reason === "no_email").map((s) => s.name);
    const recent = r.skipped.filter((s) => s.reason === "just_sent").length;
    return {
      notice: `${r.sent.length} ${r.sent.length === 1 ? "statement" : "statements"} sent.`
        + (none.length > 0 ? ` No email address: ${none.slice(0, 5).join(", ")}${none.length > 5 ? ` and ${none.length - 5} more` : ""}.` : "")
        + (recent > 0 ? ` ${recent} sent a moment ago were not sent again.` : ""),
    };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot send statements." };
    return formStateFromError(error);
  }
}

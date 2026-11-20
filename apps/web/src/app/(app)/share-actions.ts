"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";

/* Server Function behind "Send by WhatsApp" on invoices and quotes. The API checks who may send them (invoices.create). */

export interface ShareState extends FormState {
  url?: string;
  whatsapp?: string;
  to?: string | null;
  expires?: string;
}

export async function shareOnWhatsApp(_: ShareState | undefined, form: FormData): Promise<ShareState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  const kind = text(form, "kind") === "quote" ? "quote" : "invoice";
  try {
    const s = await ledgerly.shareDocument(kind, text(form, "id") ?? "", { token, companyId });
    if (kind === "quote") refresh();   // a draft quote shared is sent
    return { url: s.url, whatsapp: s.whatsapp_url, to: s.to, expires: s.expires_at.slice(0, 10) };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot send it." };
    return formStateFromError(error);
  }
}

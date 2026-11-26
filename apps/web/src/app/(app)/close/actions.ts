"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { journalBody } from "@/lib/journal";
import { getSession } from "@/lib/session";

/* Server Functions behind the year-end close. The API checks who may close (periods.close) and reopen (periods.reopen). */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) return { error: "Only an owner or admin can do this." };
  return formStateFromError(error);
}

export async function closeYear(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.closeYear(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: `Year closed: ${done.net_profit} moved to retained earnings (entry #${done.entry.number}).` };
  } catch (error) {
    return refusal(error);
  }
}

/** Post an audit adjustment into a closed year: dated its last day, the year closed again with it. */
export async function postAdjustment(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const { memo, lines } = journalBody(form);
    const done = await ledgerly.postAdjustment(text(form, "id") ?? "", { memo, lines }, ctx);
    refresh();
    return { notice: `Adjustment posted (entry #${done.adjustment.number}); the year is closed again with it (entry #${done.closing.number}), profit now ${done.net_profit}.` };
  } catch (error) {
    return refusal(error);
  }
}

export async function finaliseAudit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  if (text(form, "confirm") !== "yes") return { error: "Tick the box to confirm the audit is signed off." };
  try {
    await ledgerly.finaliseAudit(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: "Audit signed off: the year takes no more adjustments and stays closed." };
  } catch (error) {
    return refusal(error);
  }
}

export async function reopenYear(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const done = await ledgerly.reopenYear(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
    refresh();
    return { notice: `Year reopened: its closing entry is reversed (entry #${done.reversal.number}).` };
  } catch (error) {
    return refusal(error);
  }
}

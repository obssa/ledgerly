"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { invoiceBody } from "@/lib/invoice";
import { getSession } from "@/lib/session";

/*
 * Server Functions behind the invoice screens. The API recalculates every amount and decides who may save
 * (invoices.create), approve (invoices.approve) and void (transactions.edit).
 */

async function context(): Promise<{ token: string; companyId: string }> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  return { token, companyId };
}

function refusal(error: unknown): FormState {
  if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
  if (error instanceof ApiError && error.status === 403) {
    return { error: (error.body as { type?: string } | null)?.type === "DemoAccount" ? "Not available in the demo: reminders are not emailed from the shared demo accounts." : "Your role cannot do this." };
  }
  // Someone else saved the draft after this form was opened: their changes are not overwritten.
  if (error instanceof ApiError && error.status === 409) {
    return { error: "Someone else changed this draft after you opened it. Open it again to see their changes, then make yours." };
  }
  // A rule of the books (closed period, approval limit, state) arrives as 422 with a message for the person.
  if (error instanceof ApiError && error.status === 422 && (error.body as { type?: string } | null)?.type) {
    return { error: (error.body as { message?: string }).message ?? "Not possible right now." };
  }
  return formStateFromError(error);
}

/**
 * Saves the draft (a new one, or the draft being edited: the form then carries its id and the version it was read at, so
 * a save over someone else's is refused); with intent=approve also posts it. Either way the invoice page opens.
 */
export async function createInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const draft = text(form, "id");
  let id: string;
  try {
    const body = invoiceBody(form);
    const version = Number(text(form, "lock_version"));
    id = draft
      ? (await ledgerly.updateInvoice(draft, { ...body, ...(Number.isInteger(version) && version > 0 ? { lock_version: version } : {}) }, ctx)).id
      : (await ledgerly.createInvoice(body, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  if (form.get("intent") === "approve") {
    try {
      await ledgerly.approveInvoice(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
      // The draft is saved. A fixed flag, never text in the URL: the invoice page says so, and Approve there says why.
      redirect(`/invoices/${encodeURIComponent(id)}?posted=0`);
    }
  }
  redirect(`/invoices/${encodeURIComponent(id)}`);
}

/** A draft credit note for a posted invoice; it opens on its own page, where it is approved like an invoice. */
export async function createCreditNote(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const invoiceId = text(form, "invoice_id") ?? "";
  const body = invoiceBody(form);
  let id: string;
  try {
    id = (await ledgerly.createCreditNote(invoiceId, { issue_date: body.issue_date, notes: body.notes, lines: body.lines, restock: form.get("restock") === "1" }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/invoices/${encodeURIComponent(id)}`);
}

export async function applyCredit(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.applyCredit(text(form, "credit_note_id") ?? "", { invoice_id: text(form, "invoice_id") ?? "", amount: (text(form, "amount") ?? "").replace(/,/g, "") }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Credit applied." };
}

/** Module 2: emails the posted invoice to the customer, with an optional message. */
export async function emailInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const { sent_to } = await ledgerly.emailInvoice(text(form, "id") ?? "", text(form, "note") ?? undefined, ctx);
    return { notice: `Sent to ${sent_to}.` };
  } catch (error) {
    return refusal(error);
  }
}

/** Module 9a: retention the customer holds back on this invoice, as a share of the total or an amount. */
export async function holdRetention(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const amount = (text(form, "amount") ?? "").replace(/,/g, "");
  const percent = (text(form, "percent") ?? "").replace(/,/g, "");
  try {
    await ledgerly.holdRetention(text(form, "id") ?? "", { ...(amount ? { amount } : percent ? { percent } : {}), held_on: text(form, "held_on") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Retention held: it is owed when released." };
}

/** Releases the retention held on this invoice: the customer owes it again. */
export async function releaseRetention(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.releaseRetention(text(form, "id") ?? "", text(form, "released_on") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Released: the customer owes it again." };
}

/** Module 2: writes off what this invoice still owes (or part of it) as a bad debt: Dr Bad debts, Cr Accounts receivable. */
export async function writeOffBadDebt(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const amount = (text(form, "amount") ?? "").replace(/,/g, "");
  try {
    await ledgerly.writeOff(text(form, "id") ?? "", { ...(amount ? { amount } : {}), date: text(form, "date") ?? "", reason: text(form, "reason") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Written off: the invoice owes that much less." };
}

/** The customer paid after all: the latest write-off is reversed and the invoice is owed again. */
export async function reverseBadDebt(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const reason = text(form, "reason");
  try {
    await ledgerly.reverseWriteOff(text(form, "id") ?? "", { date: text(form, "date") ?? "", ...(reason ? { reason } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Reversed: the customer owes it again." };
}

/** Module 2: batch invoices: this invoice's lines to each customer ticked, one invoice each, all or none. */
export async function batchInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const contactIds = form.getAll("contact_ids").filter((v): v is string => typeof v === "string" && v !== "");
  if (contactIds.length === 0) return { error: "Tick at least one customer." };
  let made: { data: { number: string | null }[] };
  try {
    made = await ledgerly.batchInvoice(text(form, "id") ?? "", { contact_ids: contactIds, issue_date: text(form, "issue_date") ?? "", post: form.get("post") === "on" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  const n = made.data.length;
  return { notice: `${n} invoice${n === 1 ? "" : "s"} made${made.data.some((m) => m.number) ? `: ${made.data.map((m) => m.number).filter(Boolean).join(", ")}` : " as drafts"}.` };
}

/** Module 2: charges interest on late payment of this invoice on a new draft invoice, and opens it. */
export async function chargeInterest(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let made: { id: string };
  try {
    made = await ledgerly.chargeInterest(text(form, "id") ?? "", { through: text(form, "through") ?? "" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/invoices/${encodeURIComponent(made.id)}`);
}

/** Module 2: emails the customer a payment reminder for this invoice now (at most once a day). */
export async function remindInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    const invoice = await ledgerly.remindInvoice(text(form, "id") ?? "", ctx);
    refresh();
    return { notice: `Reminder sent to ${invoice.reminders?.[0]?.sent_to ?? "the customer"}.` };
  } catch (error) {
    return refusal(error);
  }
}

/** 077: "Repeat this invoice": a recurring invoice from this one's customer, lines and notes. */
export async function repeatInvoice(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.repeatInvoice(text(form, "id") ?? "", {
      frequency: text(form, "frequency") ?? "", next_on: text(form, "next_on") ?? "", post: form.get("post") === "on",
      ...(text(form, "ends_on") ? { ends_on: text(form, "ends_on") } : {}), ...(text(form, "name") ? { name: text(form, "name") } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect("/invoices/recurring");
}

/** 077: pause, resume, or make what has come due now. */
export async function recurringInvoiceStep(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const step = text(form, "step");
  if (step !== "pause" && step !== "resume" && step !== "make-due") return { error: "Unknown action." };
  let result: { made?: string[]; error?: string | null; next_on: string };
  try {
    result = await ledgerly.recurringInvoiceStep(text(form, "id") ?? "", step, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  if (step === "make-due") {
    if (result.error) return { error: `Paused: ${result.error}` };
    const n = result.made?.length ?? 0;
    return { notice: n === 0 ? `Nothing due yet: next on ${result.next_on}.` : `${n} invoice${n === 1 ? "" : "s"} made; next on ${result.next_on}.` };
  }
  return { notice: step === "pause" ? "Paused." : `Resumed: next on ${result.next_on}.` };
}

export async function invoiceAction(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  const action = form.get("action");
  try {
    if (action === "approve") await ledgerly.approveInvoice(id, ctx);
    else if (action === "submit") await ledgerly.submitInvoice(id, ctx);
    else if (action === "void") await ledgerly.voidInvoice(id, text(form, "reason") ?? "", ctx);
    else return { error: "Unknown action." };
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: action === "approve" ? "Posted." : action === "submit" ? "Sent for approval." : "Voided. The number is kept and the entry reversed." };
}

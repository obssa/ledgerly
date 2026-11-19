"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { getSession } from "@/lib/session";
import { countTotal, denominationsFrom } from "@/lib/cash";
import { reconciliationBody } from "@/lib/reconcile";
import { statementFrom } from "@/lib/statements";

/* Server Functions behind banking. The API checks who may open accounts (banking.manage), transfer and deposit. */

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
const DETAILS = ["bank_name", "account_number", "branch", "provider", "wallet_number"] as const;

function details(form: FormData): Record<string, string> | undefined {
  if (!DETAILS.some((d) => form.has(`bank_details.${d}`))) return undefined;
  const out: Record<string, string> = {};
  for (const d of DETAILS) {
    const v = text(form, `bank_details.${d}`);
    if (v) out[d] = v;
  }
  return out;
}

export async function createBankAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let id: string;
  try {
    id = (await ledgerly.createBankAccount({ name: text(form, "name") ?? "", kind: text(form, "kind") ?? "bank",
      ...(text(form, "currency") ? { currency: text(form, "currency") } : {}), ...(details(form) ? { bank_details: details(form) } : {}) }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/banking/${encodeURIComponent(id)}`);
}

export async function saveBankAccount(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.updateBankAccount(text(form, "id") ?? "", { ...(form.has("name") ? { name: text(form, "name") ?? "" } : {}),
      ...(form.has("float_amount") ? { float_amount: amount(text(form, "float_amount")) || null } : {}),
      ...(form.has("is_active") ? { is_active: form.get("is_active") === "true" } : {}), ...(details(form) ? { bank_details: details(form) } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Saved." };
}

export async function transferMoney(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.transferMoney({ from_account_id: text(form, "from_account_id") ?? "", to_account_id: text(form, "to_account_id") ?? "",
      date: text(form, "date") ?? "", amount: amount(text(form, "amount")), ...(amount(text(form, "fee")) ? { fee: amount(text(form, "fee")) } : {}),
      ...(amount(text(form, "amount_received")) ? { amount_received: amount(text(form, "amount_received")) } : {}),
      ...(amount(text(form, "fx_rate")) ? { fx_rate: amount(text(form, "fx_rate")) } : {}),
      ...(amount(text(form, "fx_rate_to")) ? { fx_rate_to: amount(text(form, "fx_rate_to")) } : {}),
      ...(text(form, "memo") ? { memo: text(form, "memo") } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Transferred and posted." };
}

export async function depositReceipts(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const receipts = (text(form, "receipts") ?? "").split(/[\n;]+/).map((r) => amount(r)).filter((r) => r !== "");
  try {
    await ledgerly.depositReceipts({ bank_account_id: text(form, "bank_account_id") ?? "", date: text(form, "date") ?? "", receipts,
      ...(text(form, "slip_number") ? { slip_number: text(form, "slip_number") } : {}), ...(amount(text(form, "fee")) ? { fee: amount(text(form, "fee")) } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Deposited and posted." };
}

export async function importStatement(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const statement = await statementFrom(form);
  if ("error" in statement) return { error: statement.error, fields: { file: [statement.error] } };
  let result: { imported: number; skipped: number; from: string; to: string };
  try {
    result = await ledgerly.importStatement(text(form, "account_id") ?? "", { csv: statement.csv, ...(statement.fileName ? { file_name: statement.fileName } : {}) }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  const skipped = result.skipped > 0 ? `; ${result.skipped} already imported, skipped` : "";
  return { notice: `Imported ${result.imported} line${result.imported === 1 ? "" : "s"} from ${result.from} to ${result.to}${skipped}.` };
}

const STEPS = { match: "Matched.", categorise: "Categorised and posted.", exclude: "Excluded.", undo: "Back to review." } as const;

/** One review step on a statement line; the step and its fields come from the form that was submitted. */
export async function reviewStatementLine(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const step = text(form, "step") as keyof typeof STEPS | undefined;
  if (!step || !(step in STEPS)) return { error: "Choose what to do with the line." };
  const body: Record<string, string> = {};
  for (const field of ["journal_line_id", "account_id", "contact_id", "memo", "reason", "fx_rate"]) {
    const v = text(form, field);
    if (v) body[field] = v;
  }
  try {
    await ledgerly.reviewStatementLine(text(form, "line_id") ?? "", step, body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: STEPS[step] };
}

/** 076: every line to review that a bank rule fits is categorised by it. */
export async function applyBankRules(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let result: { categorised: number; skipped: { rule: string; reason: string }[] };
  try {
    result = await ledgerly.applyBankRules(text(form, "account_id") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  const skipped = result.skipped.length > 0 ? ` ${result.skipped.length} left to review: ${result.skipped[0].reason}` : "";
  return { notice: `${result.categorised} line${result.categorised === 1 ? "" : "s"} categorised by the rules.${skipped}` };
}

/** 076: a new bank rule, or a change to one (id given). */
export async function saveBankRule(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const body: Record<string, unknown> = {};
  for (const f of ["name", "contains", "target_account_id", "direction", "memo"]) if (text(form, f)) body[f] = text(form, f);
  for (const f of ["bank_account_id", "contact_id"]) body[f] = text(form, f) || null;
  for (const f of ["amount_min", "amount_max"]) body[f] = (text(form, f) ?? "").replace(/[,\s]/g, "") || null;
  if (text(form, "priority")) body.priority = Number(text(form, "priority"));
  const id = text(form, "id");
  try {
    if (id) await ledgerly.updateBankRule(id, body, ctx);
    else await ledgerly.createBankRule(body, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: id ? "Rule saved." : "Rule added. Lines to review that it fits show it as a suggestion." };
}

/** 076: a rule archived (it fits nothing), brought back, or deleted. */
export async function bankRuleStep(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  const step = text(form, "step");
  try {
    if (step === "delete") await ledgerly.deleteBankRule(id, ctx);
    else await ledgerly.updateBankRule(id, { is_active: step === "restore" }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: step === "delete" ? "Rule deleted." : step === "restore" ? "Rule back in use." : "Rule archived: it fits nothing now." };
}

export async function startReconciliation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const accountId = text(form, "account_id") ?? "";
  let id: string;
  try {
    id = (await ledgerly.startReconciliation(accountId, { statement_date: text(form, "statement_date") ?? "", ending_balance: amount(text(form, "ending_balance")) }, ctx)).id;
  } catch (error) {
    return refusal(error);
  }
  redirect(`/banking/${encodeURIComponent(accountId)}/reconcile/${encodeURIComponent(id)}`);
}

/** Save the ticks (and the statement's date and balance); with intent=finish, then finish at a zero difference. */
export async function saveReconciliation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const id = text(form, "id") ?? "";
  try {
    await ledgerly.saveReconciliation(id, reconciliationBody(form), ctx);
    if (text(form, "intent") === "finish") {
      const done = await ledgerly.completeReconciliation(id, ctx);
      refresh();
      return { notice: `Reconciled to ${done.statement_date}. The account is locked through that date.` };
    }
  } catch (error) {
    refresh();
    return refusal(error);
  }
  refresh();
  return { notice: "Saved." };
}

export async function undoReconciliation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  try {
    await ledgerly.undoReconciliation(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  return { notice: "Undone. Its entries can be ticked again and the period is open." };
}

export async function discardReconciliation(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  const accountId = text(form, "account_id") ?? "";
  try {
    await ledgerly.discardReconciliation(text(form, "id") ?? "", text(form, "reason") ?? "", ctx);
  } catch (error) {
    return refusal(error);
  }
  redirect(`/banking/${encodeURIComponent(accountId)}/reconcile`);
}

/** Count a cash box: by name, with the notes and coins (or the total); a difference is posted to cash over and short. */
export async function countCash(_: FormState | undefined, form: FormData): Promise<FormState> {
  const ctx = await context();
  let pieces: Record<string, number>;
  try {
    pieces = denominationsFrom(form);
  } catch {
    return { error: "Enter whole numbers of notes and coins.", fields: { denominations: ["Whole numbers only."] } };
  }
  const counted = amount(text(form, "counted"));
  let result: { counted: string; difference: string; entry: number | null };
  try {
    result = await ledgerly.countCash(text(form, "account_id") ?? "", {
      counted_on: text(form, "counted_on") ?? "", counted_by_name: text(form, "counted_by_name") ?? "",
      ...(Object.keys(pieces).length > 0 ? { denominations: pieces } : { counted: counted || countTotal(pieces) }),
      ...(text(form, "note") ? { note: text(form, "note") } : {}),
    }, ctx);
  } catch (error) {
    return refusal(error);
  }
  refresh();
  const diff = result.difference;
  return { notice: diff === "0.00" ? `Counted ${result.counted}: as the books say.`
    : `Counted ${result.counted}: ${diff.startsWith("-") ? `${diff.slice(1)} short` : `${diff} over`}, posted to cash over and short (entry #${result.entry}).` };
}

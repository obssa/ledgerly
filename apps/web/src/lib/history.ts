/**
 * A document's history in words: what each audit record was, and what it changed. Amounts stay the API's decimal
 * strings; nothing here does arithmetic on money.
 */

export interface HistoryAuditRow {
  id: number | string;
  action: string;
  user_id: string | null;
  user_name?: string | null;
  actor_type?: string;
  before: unknown;
  after: unknown;
  reason: string | null;
  created_at: string;
}

export interface HistoryVersion {
  id: string;
  version: number;
  reason: string;
  edited_by: string | null;
  edited_by_name?: string | null;
  created_at: string;
  snapshot: Record<string, unknown>;
}

export interface DocumentHistory {
  versions: HistoryVersion[];
  audit: HistoryAuditRow[];
}

export type Change =
  | { kind: "field"; label: string; from: string; to: string }
  | { kind: "line"; change: "added" | "removed"; text: string };

const ACTIONS: Record<string, string> = {
  created: "Created as a draft",
  credit_note_created: "Credit note created",
  supplier_credit_created: "Supplier credit created",
  draft_saved: "Draft changed",
  submitted: "Sent for approval",
  submitted_for_approval: "Sent for approval",
  approval_rejected: "Sent back from approval",
  posted: "Approved and posted",
  edited_after_posting: "Corrected after posting",
  voided: "Voided",
  invoice_emailed: "Emailed to the customer",
  invoice_shared: "Shared by WhatsApp",
  invoice_reminder_sent: "Reminder sent",
  payment_allocated: "Payment applied",
  credit_applied: "Credit applied",
  retention_held: "Retention held",
  retention_released: "Retention released",
  bad_debt_written_off: "Written off as a bad debt",
  bad_debt_reversed: "Bad-debt write-off reversed",
  late_interest_charged: "Late interest charged",
  tags_set: "Tags changed",
  attachment_removed: "Attachment removed",
};

/** "edited_after_posting" → "Corrected after posting"; anything unknown in plain words. */
export function actionLabel(action: string): string {
  return ACTIONS[action] ?? (action.charAt(0).toUpperCase() + action.slice(1)).replace(/_/g, " ");
}

const FIELDS: Record<string, string> = {
  contact_id: "Customer or supplier",
  number: "Number",
  reference: "Supplier's invoice number",
  issue_date: "Date",
  due_date: "Due date",
  currency: "Currency",
  fx_rate: "Exchange rate",
  notes: "Notes",
  salesperson_id: "Salesperson",
  total: "Total",
};

/** The API stores before/after as JSON text; older rows may already be objects. */
function parse(value: unknown): Record<string, unknown> | null {
  if (value === null || value === undefined || value === "") return null;
  if (typeof value === "object") return value as Record<string, unknown>;
  if (typeof value !== "string") return null;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? (parsed as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

const show = (v: unknown): string => (v === null || v === undefined || v === "" ? "—" : String(v));

/** "Roasting service × 3 @ 1000.00" from a stored line (an object) or the short text drafts already keep. */
function lineText(line: unknown): string {
  if (typeof line === "string") return line.replace(/(\d+)\.0+(?= @)/, "$1");
  if (!line || typeof line !== "object") return "";
  const l = line as Record<string, unknown>;
  const qty = String(l.quantity ?? "").replace(/(\.\d*?)0+$/, "$1").replace(/\.$/, "");
  return `${show(l.description)} × ${qty} @ ${show(l.unit_price)}`;
}

function lineChanges(before: unknown, after: unknown): Change[] {
  const a = (Array.isArray(before) ? before : []).map(lineText);
  const b = (Array.isArray(after) ? after : []).map(lineText);
  const left = [...b];
  const removed: string[] = [];
  for (const line of a) {
    const at = left.indexOf(line);
    if (at >= 0) left.splice(at, 1);
    else removed.push(line);
  }
  return [
    ...removed.map((text) => ({ kind: "line" as const, change: "removed" as const, text })),
    ...left.map((text) => ({ kind: "line" as const, change: "added" as const, text })),
  ];
}

/** The fields and lines one change touched, between two states of the document. */
export function diffStates(before: Record<string, unknown> | null, after: Record<string, unknown> | null): Change[] {
  if (!before || !after) return [];
  const out: Change[] = [];
  for (const [key, label] of Object.entries(FIELDS)) {
    if (!(key in before) && !(key in after)) continue;
    const from = show(before[key]);
    const to = show(after[key]);
    if (from !== to) out.push({ kind: "field", label, from, to });
  }
  return [...out, ...lineChanges(before.lines, after.lines)];
}

/** What an audit record changed: a correction carries its own field-by-field list; a draft save, before and after. */
export function changesOf(row: HistoryAuditRow): Change[] {
  const after = parse(row.after);
  const changes = after?.changes;
  if (changes && typeof changes === "object") {
    const before: Record<string, unknown> = {};
    const next: Record<string, unknown> = {};
    for (const [key, c] of Object.entries(changes as Record<string, { old?: unknown; new?: unknown }>)) {
      before[key] = c?.old;
      next[key] = c?.new;
    }
    return diffStates(before, next);
  }
  if (row.action === "draft_saved") return diffStates(parse(row.before), after);
  return [];
}

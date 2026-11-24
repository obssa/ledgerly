/**
 * Scheduled supplier payments (103): the bills a plan pays, read from its form, and how near its day is, in words.
 * A bill is in the plan when its box is ticked (bill:<id>); its amount (amount:<id>) is what is still due when empty.
 */
export function scheduleBills(form: FormData): { bill_id: string; amount: string | null }[] {
  const out: { bill_id: string; amount: string | null }[] = [];
  for (const [key, value] of form.entries()) {
    if (!key.startsWith("bill:") || value !== "on") continue;
    const id = key.slice(5);
    if (!/^[0-9a-f-]{36}$/i.test(id)) continue;
    const raw = form.get(`amount:${id}`);
    const amount = typeof raw === "string" ? raw.replace(/[,\s]/g, "") : "";
    out.push({ bill_id: id, amount: amount === "" ? null : amount });
  }
  return out;
}

/** "Due today", "2 days late", "In 3 days", "Tomorrow": for a plan still scheduled, against the company's today. */
export function dueLabel(payOn: string, today: string): { text: string; tone: "neutral" | "warn" | "bad" } {
  const days = Math.round((Date.parse(`${payOn}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86_400_000);
  if (Number.isNaN(days)) return { text: payOn, tone: "neutral" };
  if (days === 0) return { text: "Due today", tone: "warn" };
  if (days < 0) return { text: days === -1 ? "1 day late" : `${-days} days late`, tone: "bad" };
  return { text: days === 1 ? "Tomorrow" : `In ${days} days`, tone: "neutral" };
}

export const METHOD_LABEL: Record<string, string> = {
  bank_transfer: "Bank transfer", cheque: "Cheque", mobile_money: "Mobile money", cash: "Cash", card: "Card", other: "Other",
};

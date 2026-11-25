/** Module 11: the company's subscription to Evenbooks (GET /subscription, 053). */
export interface Subscription {
  plan: string;
  plan_name: string;
  status: "trial" | "active" | "past_due" | "suspended" | "cancelled";
  until: string | null;
  days_left: number | null;
  read_only: boolean;
  demo: boolean;
  enforced: boolean;
  instructions: string | null;
  contact: string;
  can: { manage: boolean };
}

const STATUS: Record<Subscription["status"], string> = {
  trial: "Trial", active: "Active", past_due: "Payment overdue", suspended: "Suspended (read-only)", cancelled: "Cancelled (read-only)",
};

export const subscriptionStatus = (s: Subscription): string => STATUS[s.status] ?? s.status;

/**
 * The line shown across the app, or null: read-only books always; an overdue payment; a trial ending within a week,
 * once billing is enforced. Never for the shared demo.
 */
export function subscriptionNotice(s: Subscription | null): { tone: "bad" | "warn"; text: string } | null {
  if (!s || s.demo) return null;
  if (s.read_only) {
    return { tone: "bad", text: `The subscription is ${s.status}: the books are read-only. You can still view and export them.${s.can.manage ? " See Settings, Plan and billing, to pay." : ""}` };
  }
  if (s.status === "past_due") {
    return { tone: "warn", text: `The payment for Evenbooks is overdue${s.until ? ` (paid until ${s.until})` : ""}. The books become read-only after a short grace period.` };
  }
  if (s.status === "trial" && s.enforced && s.days_left !== null && s.days_left <= 7) {
    const when = s.days_left <= 0 ? "today" : s.days_left === 1 ? "tomorrow" : `in ${s.days_left} days`;
    return { tone: "warn", text: `The free trial ends ${when}.${s.can.manage ? " See Settings, Plan and billing, to continue." : ""}` };
  }
  return null;
}

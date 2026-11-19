/** How each requisition status reads (084). */
export const REQUISITION_STATUS: Record<string, { label: string; tone: "neutral" | "warn" | "good" | "bad" }> = {
  requested: { label: "Waiting for a decision", tone: "warn" }, approved: { label: "Approved, to order", tone: "warn" },
  ordered: { label: "Ordered", tone: "good" }, rejected: { label: "Rejected", tone: "bad" }, withdrawn: { label: "Withdrawn", tone: "neutral" },
};

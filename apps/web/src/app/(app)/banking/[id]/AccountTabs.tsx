import { Segmented } from "@/components/ui";

export type AccountView = "register" | "statement" | "reconcile" | "cheques";

/**
 * The pages of one money account, as tabs: its register, the statement lines to review, reconciling, and (for a
 * bank account) cheques. Undeposited funds has only its register, so no tabs.
 */
export function AccountTabs({ id, current, kind }: { id: string; current: AccountView; kind?: string }) {
  if (kind === "undeposited_funds") return null;
  const base = `/banking/${encodeURIComponent(id)}`;
  const items = [
    { key: "register", href: base, label: "Register", active: current === "register" },
    { key: "statement", href: `${base}/statement`, label: "Statement lines", active: current === "statement" },
    { key: "reconcile", href: `${base}/reconcile`, label: "Reconcile", active: current === "reconcile" },
    ...(kind === undefined || kind === "bank" ? [{ key: "cheques", href: `${base}/cheques`, label: "Cheques", active: current === "cheques" }] : []),
  ];
  return <Segmented label="This account" items={items} />;
}

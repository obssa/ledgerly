/** Module 11: a permission an owner or admin may give a custom role, as the API names it. */
export interface Grantable {
  permission: string;
  label: string;
}

export interface GrantGroup {
  area: string;
  items: { permission: string; label: string }[];
}

/** The permissions grouped by area for the picker: "Sales: post invoices" goes under Sales as "post invoices". */
export function groupGrantable(grantable: Grantable[]): GrantGroup[] {
  const groups = new Map<string, GrantGroup>();
  for (const g of grantable) {
    const at = g.label.indexOf(": ");
    const area = at > 0 ? g.label.slice(0, at) : "Other";
    const label = at > 0 ? g.label.slice(at + 2) : g.label;
    if (!groups.has(area)) groups.set(area, { area, items: [] });
    groups.get(area)!.items.push({ permission: g.permission, label: label.charAt(0).toUpperCase() + label.slice(1) });
  }
  return [...groups.values()];
}

/** A custom role in a few words: "Custom: 4 permissions". company.view comes with every role and is not counted. */
export function roleSummary(role: string, permissions: string[]): string {
  if (role !== "custom") return role;
  const n = permissions.filter((p) => p !== "company.view").length;
  return `custom: ${n} permission${n === 1 ? "" : "s"}`;
}

/* Module 10/11: reading an audit entry: what changed between its before and after. */

type Json = Record<string, unknown> | null;

const show = (v: unknown): string => (v === null || v === undefined ? "—" : typeof v === "object" ? JSON.stringify(v) : String(v));

/** Each field that differs, with its value before and after (a new record shows only its after; a removal its before). */
export function changesOf(before: Json, after: Json, limit = 8): { key: string; from: string; to: string }[] {
  const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];
  return keys
    .filter((k) => JSON.stringify((before ?? {})[k]) !== JSON.stringify((after ?? {})[k]))
    .slice(0, limit)
    .map((k) => ({ key: k, from: before ? show(before[k]) : "", to: after ? show(after[k]) : "" }));
}

/** An action name as words: "journal_reversed" as "Journal reversed". */
export const actionLabel = (a: string) => (a.charAt(0).toUpperCase() + a.slice(1)).replace(/_/g, " ");

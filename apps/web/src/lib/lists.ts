import type { PickedRange } from "./periodParams";

/**
 * The everyday lists (invoices, bills, payments ...) by period, as QuickBooks' lists are: every date preset, All dates
 * by default so nothing disappears, and pages of 50 that keep the period as they go (the API's cursor).
 */
import type { ListQuery } from "./api";
export type { ListQuery };

/** A page of a list: its rows, and where the pages before and after it start. */
export type Paged<T> = T[] & { next: string | null; prev: string | null };

export function paged<T>(r: { data: T[]; next_cursor?: string | null; prev_cursor?: string | null }): Paged<T> {
  return Object.assign([...r.data], { next: r.next_cursor ?? null, prev: r.prev_cursor ?? null });
}

export const emptyPage = <T>(rows: T[] = []): Paged<T> => Object.assign([...rows], { next: null, prev: null });

type Query = Record<string, string | string[] | undefined>;

/** The API query for a list: the period's ends (none for an open end) and the page's cursor when it is one. */
export function listQuery(q: Query, picked: PickedRange): ListQuery {
  const cursor = typeof q.cursor === "string" && /^[A-Za-z0-9_=-]{1,1000}$/.test(q.cursor) ? q.cursor : undefined;
  return { from: picked.from ?? undefined, to: picked.to ?? undefined, ...(cursor ? { cursor } : {}) };
}

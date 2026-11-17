import { DEFAULT_TIME_ZONE } from "./dates";

/** Module 11: one device someone is signed in on (GET /auth/sessions). */
export interface SignedInSession {
  id: string;
  device: string;
  address: string | null;
  signed_in_at: string | null;
  last_active_at: string | null;
  expires_at: string | null;
  current: boolean;
}

const format = new Intl.DateTimeFormat("en-GB", { timeZone: DEFAULT_TIME_ZONE, day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false });

/** A moment as people read it, in Addis Ababa time: "17 Nov 2026, 09:05". */
export function sessionTime(iso: string | null): string {
  if (!iso) return "";
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? "" : format.format(at);
}

/** This device first, then the most recently active. */
export function sortSessions(sessions: SignedInSession[]): SignedInSession[] {
  return [...sessions].sort((a, b) => Number(b.current) - Number(a.current) || (b.last_active_at ?? "").localeCompare(a.last_active_at ?? ""));
}

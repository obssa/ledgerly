import { cookies } from "next/headers";
import { api } from "./api";

/*
 * The staff console (SECURITY.md S8): Evenbooks' own support staff, apart from customers. Their sign-in lives in its
 * own HttpOnly cookie, sent only to /staff pages, and is a token the API's database checks on every call; the API also
 * answers staff routes only from allow-listed addresses (the visitor's, passed on by this server).
 */

export const STAFF_COOKIE = "ledgerly_staff";

export const STAFF_COOKIE_OPTIONS = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "strict" as const,
  path: "/staff",
  maxAge: 8 * 60 * 60,
};

export async function staffToken(): Promise<string | null> {
  const value = (await cookies()).get(STAFF_COOKIE)?.value ?? "";
  return /^[0-9a-f]{64}$/.test(value) ? value : null;
}

export interface StaffCompany { id: string; legal_name: string; trading_name: string | null; country_code: string; status: string; created_at: string }

export interface StaffSessionView {
  session: { id: string; expires_at: string; reason: string };
  company: { id: string; legal_name: string; trading_name: string | null; country_code: string; base_currency: string; status: string;
    conversion_date: string | null; books_closed_until: string | null; created_at: string };
  documents: { type: string; status: string; n: number }[];
  journal_entries: { n: number; last: string | null };
  trial_balance: { code: string; name: string; type: string; debit: string; credit: string }[];
  audit: { action: string; subject_type: string; actor_type: string; reason: string | null; created_at: string }[];
}

export const staffApi = {
  signIn: (body: { email: string; password: string; code: string }) => api<{ token: string; expires_at: string }>("staff/login", { method: "POST", body }),
  setup: (token: string) => api<{ email: string; name: string; secret: string; otpauth_uri: string }>("staff/setup", { query: { token } }),
  completeSetup: (body: { token: string; password: string; secret: string; code: string }) => api<{ set_up: boolean }>("staff/setup", { method: "POST", body }),
  me: (token: string) => api<{ name: string; email: string }>("staff/me", { token }),
  signOut: (token: string) => api<unknown>("staff/logout", { token, method: "POST", body: {} }),
  companies: (token: string, search: string) => api<{ data: StaffCompany[] }>("staff/companies", { token, query: { search } }),
  startSession: (token: string, body: { company_id: string; reason: string; minutes?: number }) => api<{ id: string }>("staff/sessions", { token, method: "POST", body }),
  session: (token: string, id: string) => api<StaffSessionView>(`staff/sessions/${encodeURIComponent(id)}`, { token }),
  endSession: (token: string, id: string) => api<unknown>(`staff/sessions/${encodeURIComponent(id)}/end`, { token, method: "POST", body: {} }),
};

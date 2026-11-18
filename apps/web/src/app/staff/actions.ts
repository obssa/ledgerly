"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { ApiError } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { STAFF_COOKIE, STAFF_COOKIE_OPTIONS, staffApi, staffToken } from "@/lib/staff";

/* The staff console (SECURITY.md S8). The API checks the address, the password, the code and the token every time. */

const value = (form: FormData, n: string) => (typeof form.get(n) === "string" ? String(form.get(n)).trim() : "");

function refusal(error: unknown, fallback: string): FormState {
  if (error instanceof ApiError && error.status === 404) return { error: "The staff console is not open from this address." };
  if (error instanceof ApiError && error.status === 429) return { error: "Too many tries: wait a minute." };
  if (error instanceof ApiError && error.status === 422) {
    const errors = (error.body as { errors?: Record<string, string[]> } | null)?.errors ?? {};
    return { error: Object.values(errors).flat()[0] ?? fallback };
  }
  throw error;
}

export async function staffSignIn(_: FormState | undefined, form: FormData): Promise<FormState> {
  let token: string;
  try {
    token = (await staffApi.signIn({ email: value(form, "email"), password: String(form.get("password") ?? ""), code: value(form, "code") })).token;
  } catch (error) {
    return refusal(error, "Wrong email, password or code.");
  }
  (await cookies()).set(STAFF_COOKIE, token, STAFF_COOKIE_OPTIONS);
  redirect("/staff");
}

export async function staffSignOut(): Promise<void> {
  const token = await staffToken();
  if (token) await staffApi.signOut(token).catch(() => null);
  (await cookies()).delete({ name: STAFF_COOKIE, path: "/staff" });
  redirect("/staff/sign-in");
}

export async function staffCompleteSetup(_: FormState | undefined, form: FormData): Promise<FormState> {
  try {
    await staffApi.completeSetup({ token: value(form, "token"), password: String(form.get("password") ?? ""), secret: value(form, "secret"), code: value(form, "code") });
  } catch (error) {
    return refusal(error, "The setup could not be finished.");
  }
  return { notice: "Your staff account is ready: sign in with your password and a code from the authenticator." };
}

export async function staffStartSession(_: FormState | undefined, form: FormData): Promise<FormState> {
  const token = await staffToken();
  if (!token) redirect("/staff/sign-in");
  let id: string;
  try {
    id = (await staffApi.startSession(token, { company_id: value(form, "company_id"), reason: value(form, "reason"), minutes: Number(value(form, "minutes")) || undefined })).id;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/staff/sign-in");
    return refusal(error, "The session could not be started.");
  }
  redirect(`/staff/sessions/${encodeURIComponent(id)}`);
}

export async function staffEndSession(form: FormData): Promise<void> {
  const token = await staffToken();
  if (token) await staffApi.endSession(token, value(form, "id")).catch(() => null);
  redirect("/staff");
}

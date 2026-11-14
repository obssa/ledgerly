"use server";

import { redirect } from "next/navigation";
import { ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { endSession, getSession, selectCompany, startSession } from "@/lib/session";

/*
 * Server Functions behind the sign-in, sign-up, sign-out and company-setup forms. They run on the web server,
 * call the API there and keep the token in an HttpOnly cookie (src/lib/session.ts). Next.js checks the
 * Origin of every Server Function call, so another site cannot submit these forms for a signed-in user.
 * redirect() works by throwing, so it is always called outside the try blocks.
 */

/** Where to go once signed in: the first company, or company setup when there is none yet. */
async function afterSignIn(token: string): Promise<string> {
  const { data } = await ledgerly.companies(token);
  if (data.length === 0) return "/onboarding";
  await selectCompany(data[0].id);
  return "/";
}

export async function signIn(_: FormState | undefined, form: FormData): Promise<FormState> {
  let next: string;
  try {
    const { token, user } = await ledgerly.login({ email: text(form, "email") ?? "", password: String(form.get("password") ?? "") });
    await startSession(token, user.name);
    next = await afterSignIn(token);
  } catch (error) {
    return formStateFromError(error);
  }
  redirect(next);
}

export async function signUp(_: FormState | undefined, form: FormData): Promise<FormState> {
  try {
    const { token, user } = await ledgerly.register({
      name: text(form, "name") ?? "",
      email: text(form, "email") ?? "",
      password: String(form.get("password") ?? ""),
    });
    await startSession(token, user.name);
  } catch (error) {
    return formStateFromError(error);
  }
  redirect("/onboarding");
}

export async function signOut(): Promise<void> {
  const { token } = await getSession();
  if (token) await ledgerly.logout(token).catch(() => undefined); // the cookie goes either way
  await endSession();
  redirect("/sign-in");
}

const OPTIONAL = ["trading_name", "base_currency", "tin", "phone", "email", "website", "calendar", "conversion_date", "industry"] as const;

export async function createCompany(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");

  const body: Record<string, unknown> = {
    legal_name: text(form, "legal_name") ?? "",
    country_code: text(form, "country_code") ?? "",
    fiscal_year_start_month: Number(text(form, "fiscal_year_start_month") ?? 1),
    vat_registered: form.get("vat_registered") === "on",
  };
  for (const name of OPTIONAL) {
    const value = text(form, name);
    if (value !== undefined) body[name] = value;
  }

  try {
    const company = await ledgerly.createCompany(body, token);
    await selectCompany(company.id);
  } catch (error) {
    return formStateFromError(error);
  }
  redirect("/");
}

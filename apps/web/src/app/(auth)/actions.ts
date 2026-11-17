"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import QRCode from "qrcode";
import { ledgerly } from "@/lib/api";
import { formStateFromError, text, type FormState } from "@/lib/forms";
import { endSession, getSession, heldChallenge, holdChallenge, replaceToken, selectCompany, startSession } from "@/lib/session";

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
    const result = await ledgerly.login({ email: text(form, "email") ?? "", password: String(form.get("password") ?? "") });
    if ("mfa_required" in result) {
      // The password was right, but there is no session until the authenticator code is too.
      await holdChallenge(result.challenge);
      next = "/sign-in/code";
    } else {
      await startSession(result.token, result.user.name);
      next = await afterSignIn(result.token);
    }
  } catch (error) {
    return formStateFromError(error);
  }
  redirect(next);
}

/**
 * One click into the shared demo company as a role (the API offers it only when DEMO_LOGINS is on). The role
 * comes from the button pressed; the API checks it against the roles it offers.
 */
export async function demoSignIn(form: FormData): Promise<void> {
  const role = text(form, "role") ?? "";
  if (!/^[a-z]{1,20}$/.test(role)) redirect("/sign-in?demo=unavailable");
  let next = "/sign-in?demo=unavailable";
  try {
    const { token, user } = await ledgerly.demoLogin(role);
    await startSession(token, user.name, role.charAt(0).toUpperCase() + role.slice(1));
    next = await afterSignIn(token);
  } catch {
    // Off, not set up, or too many tries: the sign-in page says so.
  }
  redirect(next);
}

/** Second step of sign-in: six digits from the authenticator app, or one of the recovery codes. */
export async function verifyCode(_: FormState | undefined, form: FormData): Promise<FormState> {
  const challenge = await heldChallenge();
  if (!challenge) return { error: "This sign-in has expired. Go back and enter your password again." };

  const entered = text(form, "code") ?? "";
  const isAppCode = /^\d{6}$/.test(entered.replace(/\s/g, ""));
  let next: string;
  try {
    const { token, user } = await ledgerly.loginWithCode(isAppCode ? { challenge, code: entered } : { challenge, recovery_code: entered });
    await startSession(token, user.name);
    next = await afterSignIn(token);
  } catch (error) {
    return formStateFromError(error);
  }
  redirect(next);
}

/** What the authenticator setup screen shows after each step. */
export interface AuthenticatorState extends FormState {
  /** To scan: the QR code as an image address, and the same secret as text for typing in. */
  qr?: string;
  secret?: string;
  /** Shown once, after the first correct code. */
  recoveryCodes?: string[];
}

export async function beginAuthenticator(): Promise<AuthenticatorState> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  try {
    const { secret, otpauth_uri } = await ledgerly.mfaEnrol(token);
    // Drawn here on the server: the secret is never sent to a third-party QR service.
    const svg = await QRCode.toString(otpauth_uri, { type: "svg", margin: 1, errorCorrectionLevel: "M" });
    return { secret, qr: `data:image/svg+xml;base64,${Buffer.from(svg).toString("base64")}` };
  } catch (error) {
    return formStateFromError(error);
  }
}

export async function confirmAuthenticator(_: AuthenticatorState | undefined, form: FormData): Promise<AuthenticatorState> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  try {
    const { recovery_codes } = await ledgerly.mfaConfirm(text(form, "code") ?? "", token);
    return { recoveryCodes: recovery_codes };
  } catch (error) {
    return formStateFromError(error);
  }
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

export async function signOutEverywhere(): Promise<void> {
  const { token } = await getSession();
  if (token) await ledgerly.logoutEverywhere(token).catch(() => undefined);
  await endSession();
  redirect("/sign-in");
}

/** Signs out one other device (Module 11). This device signs out with the buttons above. */
export async function endDeviceSession(form: FormData): Promise<void> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  const id = String(form.get("id") ?? "");
  if (/^\d{1,18}$/.test(id)) await ledgerly.endDeviceSession(id, token).catch(() => undefined); // gone already: the list shows it
  refresh();
}

/** Sends the confirmation email again (company setup needs a confirmed address). */
export async function resendVerification(): Promise<FormState> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  try {
    return { notice: (await ledgerly.resendVerification(token)).message };
  } catch (error) {
    return formStateFromError(error);
  }
}

export async function forgotPassword(_: FormState | undefined, form: FormData): Promise<FormState> {
  try {
    return { notice: (await ledgerly.forgotPassword(text(form, "email") ?? "")).message };
  } catch (error) {
    return formStateFromError(error);
  }
}

export async function resetPassword(_: FormState | undefined, form: FormData): Promise<FormState> {
  try {
    const { message } = await ledgerly.resetPassword({ token: text(form, "token") ?? "", password: String(form.get("password") ?? "") });
    await endSession(); // the API ended every session; this browser's cookies go too
    return { notice: message };
  } catch (error) {
    return formStateFromError(error);
  }
}

/** Module 11: accepting an invitation from its emailed link; a new account chooses its name and password here. */
export async function acceptInvitation(_: FormState | undefined, form: FormData): Promise<FormState> {
  try {
    const password = String(form.get("password") ?? "");
    const name = text(form, "name");
    const { company, email } = await ledgerly.acceptInvitation({ token: text(form, "token") ?? "", ...(password ? { password } : {}), ...(name ? { name } : {}) });
    return { notice: `You joined ${company}. Sign in as ${email} to start.` };
  } catch (error) {
    return formStateFromError(error);
  }
}

export async function changePassword(_: FormState | undefined, form: FormData): Promise<FormState> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  try {
    const fresh = await ledgerly.changePassword(
      { current_password: String(form.get("current_password") ?? ""), password: String(form.get("password") ?? "") },
      token,
    );
    await replaceToken(fresh.token);
    return { notice: "Your password is changed. Every other device was signed out." };
  } catch (error) {
    return formStateFromError(error);
  }
}

const OPTIONAL = ["organisation_id", "trading_name", "base_currency", "tin", "phone", "email", "website", "calendar", "conversion_date", "industry"] as const;

/** Company switcher: works in another company without signing out. Only a company of the person's own list. */
export async function switchCompany(form: FormData): Promise<void> {
  const { token } = await getSession();
  if (!token) redirect("/sign-in");
  const id = text(form, "company_id");
  const { data } = await ledgerly.companies(token);
  if (id && data.some((c) => c.id === id)) await selectCompany(id);
  redirect("/");
}

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

import "server-only";
import { cookies } from "next/headers";

/**
 * The signed-in session lives in HttpOnly cookies, read only on the server: the API token never reaches
 * browser JavaScript, so a script injected into a page cannot steal it. Cookies can be set only from Server
 * Functions (src/app/(auth)/actions.ts), not while a page renders.
 */
export const TOKEN_COOKIE = "ledgerly_token";
export const COMPANY_COOKIE = "ledgerly_company";
const NAME_COOKIE = "ledgerly_name";
/** Set for a demo sign-in: the role's label, shown in the demo banner. */
const DEMO_COOKIE = "ledgerly_demo";

/** The API's tokens expire after SANCTUM_TOKEN_MINUTES (720); the cookie goes with them. */
const MAX_AGE_SECONDS = 60 * 60 * 12;

const options = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
  maxAge: MAX_AGE_SECONDS,
};

export interface Session {
  token?: string;
  companyId?: string;
  name?: string;
  /** The demo role's label when signed in to the shared demo company. */
  demo?: string;
}

export async function getSession(): Promise<Session> {
  const jar = await cookies();
  return {
    token: jar.get(TOKEN_COOKIE)?.value,
    companyId: jar.get(COMPANY_COOKIE)?.value,
    name: jar.get(NAME_COOKIE)?.value,
    demo: jar.get(DEMO_COOKIE)?.value,
  };
}

/** A demo session lasts as long as its API token (DEMO_TOKEN_MINUTES, 120). */
const DEMO_MAX_AGE_SECONDS = 60 * 60 * 2;

/**
 * The session's cookies all live as long as its API token: 12 hours, or 2 for a demo sign-in. They must expire
 * together. A company cookie that ends before the token leaves a person signed in with no company, and every page
 * then sent them to company setup (26 Nov 2026: every real sign-in got the demo's 2 hours, see selectCompany).
 */
export function sessionCookieOptions(demo: boolean) {
  return demo ? { ...options, maxAge: DEMO_MAX_AGE_SECONDS } : options;
}

/**
 * A signed-in session, written all at once: token, name and, when the person has one, the company to work in.
 * Callers ask the API for the companies BEFORE calling this, so a failure on the way leaves no half session behind.
 */
export async function startSession(token: string, name: string, demo?: string, companyId?: string): Promise<void> {
  const jar = await cookies();
  const opts = sessionCookieOptions(Boolean(demo));
  jar.set(TOKEN_COOKIE, token, opts);
  jar.set(NAME_COOKIE, name, opts);
  if (demo) jar.set(DEMO_COOKIE, demo, opts);
  else jar.delete(DEMO_COOKIE);
  if (companyId) jar.set(COMPANY_COOKIE, companyId, opts);
  else jar.delete(COMPANY_COOKIE);
  jar.delete(CHALLENGE_COOKIE);
}

/**
 * Between the password and the authenticator code there is no session yet, only the API's challenge. It is
 * kept like the token: HttpOnly, and for the five minutes the API accepts it.
 */
const CHALLENGE_COOKIE = "ledgerly_challenge";

export async function holdChallenge(challenge: string): Promise<void> {
  (await cookies()).set(CHALLENGE_COOKIE, challenge, { ...options, maxAge: 60 * 5 });
}

export async function heldChallenge(): Promise<string | undefined> {
  return (await cookies()).get(CHALLENGE_COOKIE)?.value;
}

/**
 * After a password change the API ends every session and hands this one a new token, valid for a new 12 hours. The
 * name and company go on with it, so they don't expire before it.
 */
export async function replaceToken(token: string): Promise<void> {
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, token, options);
  for (const name of [NAME_COOKIE, COMPANY_COOKIE]) {
    const value = jar.get(name)?.value;
    if (value) jar.set(name, value, options);
  }
}

/**
 * The company to work in, for as long as the session lasts. Whether this is a demo session is read from the cookie's
 * VALUE: within one Server Function a cookie deleted earlier (startSession deletes the demo cookie at every real
 * sign-in) is still returned by get(), as an entry with an empty value. Testing the entry itself was always true,
 * so every company cookie got the demo's 2 hours while the token had 12.
 */
export async function selectCompany(companyId: string): Promise<void> {
  const jar = await cookies();
  jar.set(COMPANY_COOKIE, companyId, sessionCookieOptions(Boolean(jar.get(DEMO_COOKIE)?.value)));
}

export async function endSession(): Promise<void> {
  const jar = await cookies();
  for (const name of [TOKEN_COOKIE, COMPANY_COOKIE, NAME_COOKIE, CHALLENGE_COOKIE, DEMO_COOKIE]) jar.delete(name);
}

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
}

export async function getSession(): Promise<Session> {
  const jar = await cookies();
  return {
    token: jar.get(TOKEN_COOKIE)?.value,
    companyId: jar.get(COMPANY_COOKIE)?.value,
    name: jar.get(NAME_COOKIE)?.value,
  };
}

export async function startSession(token: string, name: string): Promise<void> {
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, token, options);
  jar.set(NAME_COOKIE, name, options);
  jar.delete(COMPANY_COOKIE);
}

export async function selectCompany(companyId: string): Promise<void> {
  (await cookies()).set(COMPANY_COOKIE, companyId, options);
}

export async function endSession(): Promise<void> {
  const jar = await cookies();
  for (const name of [TOKEN_COOKIE, COMPANY_COOKIE, NAME_COOKIE]) jar.delete(name);
}

import { ApiError, ledgerly } from "@/lib/api";
import { getSession } from "@/lib/session";

/* Module 1 (100): the logo removed, from the settings page's form (only a form of this site: the Origin is checked). */
export async function POST(request: Request): Promise<Response> {
  const origin = request.headers.get("origin");
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  if (!origin || !host || new URL(origin).host !== host) return new Response("Forbidden.", { status: 403 });
  const { token, companyId } = await getSession();
  if (!token || !companyId) return Response.redirect(new URL("/sign-in", request.url), 303);
  const back = (flag: string) => new Response(null, { status: 303, headers: { Location: `/settings?logo=${flag}` } });
  try {
    await ledgerly.removeLogo({ token, companyId });
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return Response.redirect(new URL("/sign-in", request.url), 303);
    if (error instanceof ApiError && error.status === 403) return back("forbidden");
    throw error;
  }
  return back("removed");
}

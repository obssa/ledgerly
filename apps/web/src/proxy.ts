import { NextResponse, type NextRequest } from "next/server";

/**
 * Sends anyone without a session to the sign-in page before a page renders. The cookie is only checked for
 * presence here; the API decides whether the token is valid, and a page that gets a 401 back goes to
 * sign-in too (src/lib/data.ts). In demo mode (no LEDGERLY_API_URL) every page is open.
 */
export function proxy(request: NextRequest) {
  if (!process.env.LEDGERLY_API_URL) return NextResponse.next();
  if (request.cookies.has("ledgerly_token")) return NextResponse.next();
  return NextResponse.redirect(new URL("/sign-in", publicOrigin(request)));
}

/**
 * Behind Cloudflare and the host's proxy, request.url carries the server's internal address. LEDGERLY_WEB_URL
 * (shared/web.env on the server) names the public one; without it, the forwarding headers are used.
 */
function publicOrigin(request: NextRequest): string {
  if (process.env.LEDGERLY_WEB_URL) return process.env.LEDGERLY_WEB_URL;
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  const proto = request.headers.get("x-forwarded-proto")?.split(",")[0]?.trim() ?? request.nextUrl.protocol.replace(":", "");
  return host ? `${proto}://${host}` : request.nextUrl.origin;
}

export const config = {
  // Everything except the signed-out pages, Next.js assets and static files.
  matcher: ["/((?!sign-in|sign-up|forgot-password|reset-password|verify-email|invitation|quote$|invoice$|invoice/pdf$|staff|_next/|favicon\\.ico|.*\\.(?:png|jpg|jpeg|gif|svg|webp|ico|txt|xml|css|js)$).*)"],
};

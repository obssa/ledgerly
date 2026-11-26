import { ApiError, ledgerly } from "@/lib/api";
import { chooseCompany } from "@/lib/companyChoice";
import { getSession, selectCompany } from "@/lib/session";

/**
 * A signed-in person whose company is missing (its cookie expired or was cleared, or they were removed from it)
 * comes here from any page: the company is chosen again from their own list and they go on to the overview. Only a
 * person with no company at all goes to company setup. A page can't set a cookie while it renders; a route can.
 *
 * Redirects are relative (Location: /): behind the host's proxy, request.url carries the server's internal address.
 */
export async function GET(): Promise<Response> {
  const { token, demo } = await getSession();
  if (!token) return to("/sign-in");
  let companies;
  try {
    companies = (await ledgerly.companies(token)).data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return to("/sign-in");
    return busy();
  }
  // Earlier demo companies are not offered to a demo session (as in the company menu).
  const choice = chooseCompany(demo ? companies.filter((c) => c.status !== "archived") : companies);
  if (choice.companyId) await selectCompany(choice.companyId);
  return to(choice.to);
}

function to(path: string): Response {
  return new Response(null, { status: 303, headers: { Location: path, "Cache-Control": "no-store" } });
}

/** The API didn't answer: say so and offer to try again, rather than send anyone to company setup by mistake. */
function busy(): Response {
  const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evenbooks is busy</title></head>
<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;text-align:center;color:#17302a">
<h1 style="font-size:1.25rem">Evenbooks is busy</h1>
<p>Your books are safe. The server took too long to answer. Please try again in a moment.</p>
<p><a href="/company/resume" style="display:inline-block;padding:.6rem 1.2rem;border-radius:.6rem;background:#0f5c4c;color:#fff;text-decoration:none">Try again</a></p>
</body></html>`;
  return new Response(html, { status: 503, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store", "Retry-After": "5" } });
}

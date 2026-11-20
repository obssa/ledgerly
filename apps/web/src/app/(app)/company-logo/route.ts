import { ApiError, apiFile, apiUpload } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * Module 1 (100): the company's logo. GET gives the image to the pages of this site (the API checks the member);
 * POST takes a new one from the plain form on the settings page (only a form of this site: the Origin is checked) and
 * opens the settings again with a fixed flag, never text from the request. The API decides the type by its content.
 */
export async function GET(): Promise<Response> {
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in first.", { status: 401 });
  try {
    const file = await apiFile("company/logo", { token, companyId });
    const sent = file.headers.get("content-type") ?? "";
    if (!sent.startsWith("image/")) return new Response("No logo.", { status: 404 });   // none yet: the API answers {logo: null}
    const type = sent.startsWith("image/jpeg") ? "image/jpeg" : "image/png";
    return new Response(file.body, { headers: { "Content-Type": type, "X-Content-Type-Options": "nosniff", "Cache-Control": "private, max-age=300" } });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) return new Response("No logo.", { status: 404 });
    throw error;
  }
}

export async function POST(request: Request): Promise<Response> {
  const origin = request.headers.get("origin");
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  if (!origin || !host || new URL(origin).host !== host) return new Response("Forbidden.", { status: 403 });
  const { token, companyId } = await getSession();
  if (!token || !companyId) return Response.redirect(new URL("/sign-in", request.url), 303);
  const back = (flag: string) => new Response(null, { status: 303, headers: { Location: `/settings?logo=${flag}` } });
  const file = (await request.formData()).get("file");
  if (!(file instanceof File) || file.size === 0) return back("none");
  const body = new FormData();
  body.append("file", file, "logo");
  try {
    await apiUpload("company/logo", body, { token, companyId });
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status === 401) return Response.redirect(new URL("/sign-in", request.url), 303);
      if (error.status === 403) return back((error.body as { type?: string } | null)?.type === "DemoAccount" ? "demo" : "forbidden");
      if (error.status === 413) return back("large");
      if (error.status === 422) return back(/256 KB|failed to upload/.test(JSON.stringify((error.body as { errors?: unknown } | null)?.errors ?? "")) ? "large" : "type");
    }
    throw error;
  }
  return back("added");
}

import { ApiError, apiUpload, attachmentPath, type AttachmentKind } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * A file attached to an invoice, bill or expense (SECURITY.md S7, 063), from a plain form on the document's page. A
 * route rather than a Server Function, so a file of up to 10 MB is passed on as it is; only a form of this site is
 * taken (the session cookie is SameSite=Lax, and the Origin is checked). The API decides the file's type by its content
 * and checks the permission. The page is opened again with a fixed flag, never text from the request.
 */
const PAGE: Record<AttachmentKind, string> = { invoice: "/invoices", bill: "/bills", expense: "/expenses", expense_claim: "/expense-claims" };

export async function POST(request: Request): Promise<Response> {
  const origin = request.headers.get("origin");
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  if (!origin || !host || new URL(origin).host !== host) return new Response("Forbidden.", { status: 403 });
  const { token, companyId } = await getSession();
  if (!token || !companyId) return Response.redirect(new URL("/sign-in", request.url), 303);

  const form = await request.formData();
  const kind = form.get("kind");
  const id = form.get("document_id");
  const file = form.get("file");
  if ((kind !== "invoice" && kind !== "bill" && kind !== "expense" && kind !== "expense_claim") || typeof id !== "string" || !/^[0-9a-f-]{36}$/i.test(id)) {
    return new Response("Not found.", { status: 404 });
  }
  const back = (flag: string) => new Response(null, { status: 303, headers: { Location: `${PAGE[kind]}/${encodeURIComponent(id)}?attachment=${flag}` } });
  if (!(file instanceof File) || file.size === 0) return back("none");

  const body = new FormData();
  body.append("file", file, file.name);
  try {
    await apiUpload(`${attachmentPath(kind)}/${encodeURIComponent(id)}/attachments`, body, { token, companyId });
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status === 401) return Response.redirect(new URL("/sign-in", request.url), 303);
      if (error.status === 403) return back((error.body as { type?: string } | null)?.type === "DemoAccount" ? "demo" : "forbidden");
      if (error.status === 413) return back("large");
      if (error.status === 422) {
        const message = JSON.stringify((error.body as { errors?: unknown } | null)?.errors ?? "");
        // A file the server's own upload limit stopped arrives as "failed to upload": it was too large.
        return back(/at most 20/.test(message) ? "full" : /10 MB|failed to upload|must be a file/.test(message) ? "large"
          : /scripts/.test(message) ? "script" : /hour/.test(message) ? "busy" : "type");
      }
    }
    throw error;
  }
  return back("added");
}

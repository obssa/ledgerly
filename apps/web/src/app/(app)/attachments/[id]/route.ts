import { ApiError, apiFile } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * A file kept with a document (SECURITY.md S7, 063). The API checks the document's permission and sends it; this hands
 * it to the browser as a download that is never sniffed, cached or run. There is no other way to reach the file.
 */
export async function GET(_: Request, { params }: { params: Promise<{ id: string }> }): Promise<Response> {
  const { id } = await params;
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to open the file.", { status: 401 });
  if (!/^[0-9a-f-]{36}$/i.test(id)) return new Response("Not found.", { status: 404 });
  try {
    const file = await apiFile(`attachments/${encodeURIComponent(id)}`, { token, companyId });
    const type = file.headers.get("content-type") ?? "application/octet-stream";
    const kept = ["application/pdf", "image/png", "image/jpeg", "image/webp"].find((t) => type.startsWith(t)) ?? "application/octet-stream";
    return new Response(file.body, {
      headers: {
        "Content-Type": kept,
        "Content-Disposition": file.headers.get("content-disposition") ?? "attachment",
        "X-Content-Type-Options": "nosniff",
        "Cache-Control": "private, no-store",
        "Content-Security-Policy": "default-src 'none'; sandbox",
      },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
      return new Response(error.status === 404 ? "Not found." : "Your role cannot open this file.", { status: error.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
    }
    throw error;
  }
}

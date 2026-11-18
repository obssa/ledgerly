import { ApiError, apiFile } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * Module 2: a posted invoice or credit note as a PDF file. The API builds it from the company's own records and checks
 * who may see it; this hands it to the browser as a download.
 */
export async function GET(_: Request, { params }: { params: Promise<{ id: string }> }): Promise<Response> {
  const { id } = await params;
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to download the invoice.", { status: 401 });
  if (!/^[0-9a-f-]{36}$/i.test(id)) return new Response("Not found.", { status: 404 });
  try {
    const file = await apiFile(`invoices/${encodeURIComponent(id)}/pdf`, { token, companyId });
    const disposition = file.headers.get("content-disposition") ?? "";
    const name = /filename="?([A-Za-z0-9._-]+\.pdf)"?/.exec(disposition)?.[1] ?? "invoice.pdf";
    return new Response(file.body, {
      headers: { "Content-Type": "application/pdf", "Content-Disposition": `attachment; filename="${name}"`, "X-Content-Type-Options": "nosniff", "Cache-Control": "private, no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
      return new Response(error.status === 422 ? "Only a posted invoice has a PDF." : error.status === 404 ? "Not found." : "Your role cannot download it.",
        { status: error.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
    }
    throw error;
  }
}

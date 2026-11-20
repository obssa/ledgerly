import { apiBaseUrl } from "@/lib/api";

/*
 * Module 2 (099): the PDF of an invoice opened from a shared link, without an account. The API checks the token by its
 * hash and builds the PDF from that invoice only; this hands it to the browser as a download.
 */
export async function GET(request: Request): Promise<Response> {
  const token = new URL(request.url).searchParams.get("token") ?? "";
  const base = apiBaseUrl();
  if (!/^[0-9a-f]{64}$/.test(token) || !base) return new Response("This link is not complete.", { status: 404, headers: { "Content-Type": "text/plain; charset=utf-8" } });
  const res = await fetch(new URL(`/api/v1/public/invoice/pdf?token=${token}`, base), { headers: { Accept: "application/pdf, application/json" }, cache: "no-store" });
  if (!res.ok) {
    return new Response(res.status === 404 ? "This link is not valid any more: ask the company to send the invoice again." : "The PDF could not be made just now.",
      { status: res.status === 404 ? 404 : 502, headers: { "Content-Type": "text/plain; charset=utf-8" } });
  }
  const name = /filename="?([A-Za-z0-9._-]+\.pdf)"?/.exec(res.headers.get("content-disposition") ?? "")?.[1] ?? "invoice.pdf";
  return new Response(res.body, {
    headers: { "Content-Type": "application/pdf", "Content-Disposition": `attachment; filename="${name}"`, "X-Content-Type-Options": "nosniff", "Cache-Control": "private, no-store" },
  });
}

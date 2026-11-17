import { ApiError, apiFile } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * Module 11: everything the company keeps, as a ZIP of CSV files. The API builds it and checks who may have it
 * (owners and admins); this hands it to the browser as it comes.
 */
export async function GET(): Promise<Response> {
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to download the company data.", { status: 401 });
  try {
    const file = await apiFile("company/export", { token, companyId });
    const disposition = file.headers.get("content-disposition") ?? "";
    const name = /filename="?([a-z0-9.-]+\.zip)"?/i.exec(disposition)?.[1] ?? "ledgerly-export.zip";
    return new Response(file.body, {
      headers: { "Content-Type": "application/zip", "Content-Disposition": `attachment; filename="${name}"`, "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
      const demo = (error.body as { type?: string } | null)?.type === "DemoAccount";
      return new Response(demo ? "Not available in the demo." : error.message, { status: error.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
    }
    throw error;
  }
}

import { ApiError, ledgerly } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * The tax audit file as a download: the API builds the CSV (documents or ledger lines of a period) and checks who may
 * have it (tax.view); this hands it to the browser as a file. A UTF-8 byte order mark lets spreadsheets read Amharic
 * and other non-Latin names correctly.
 */

const date = (v: string | null) => (v && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

export async function GET(request: Request): Promise<Response> {
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to download the tax audit file.", { status: 401 });
  const q = new URL(request.url).searchParams;
  const part = q.get("part") === "documents" ? "documents" : "ledger";
  try {
    const file = await ledgerly.taxAuditFile({ part, from: date(q.get("from")), to: date(q.get("to")) }, { token, companyId });
    const name = file.filename.replace(/[^a-z0-9.-]/gi, "") || "tax-audit-file.csv";
    return new Response(`﻿${file.csv}`, {
      headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": `attachment; filename="${name}"`, "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) return new Response(error.message, { status: error.status });
    throw error;
  }
}

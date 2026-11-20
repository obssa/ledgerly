import { ApiError, ledgerly } from "@/lib/api";
import { getSession } from "@/lib/session";

/*
 * The bank payment file as a download: the API builds the CSV from the payments recorded and checks who may have it
 * (payments.pay); this hands it to the browser as a file, with a UTF-8 byte order mark so names read correctly.
 */

const date = (v: string | null) => (v && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);
const uuid = (v: string | null) => (v && /^[0-9a-f-]{36}$/i.test(v) ? v : undefined);

export async function GET(request: Request): Promise<Response> {
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to download the payment file.", { status: 401 });
  const q = new URL(request.url).searchParams;
  try {
    const file = await ledgerly.bankPaymentFile({ date: date(q.get("date")), account_id: uuid(q.get("account")), method: q.get("method") === "mobile_money" ? "mobile_money" : "bank_transfer", download: true },
      { token, companyId });
    const name = file.filename.replace(/[^a-z0-9.-]/gi, "") || "bank-payments.csv";
    return new Response(`﻿${file.csv}`, {
      headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": `attachment; filename="${name}"`, "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) return new Response(error.message, { status: error.status });
    throw error;
  }
}

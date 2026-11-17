import { ApiError, ledgerly } from "@/lib/api";
import { toCsv } from "@/lib/csv";
import { getReportPeriod } from "@/lib/data";
import { EXPORTABLE, exportRows, type ExportableReport } from "@/lib/report-exports";
import { isDate } from "@/lib/reports";
import { getSession } from "@/lib/session";

/*
 * Module 10: a report as a CSV file. The report is read from the API under the person's own session (so their role
 * decides what they get), and the export is recorded in the audit log before the file is handed over (who, when, which
 * report, which period, how many rows). A UTF-8 byte order mark lets spreadsheets read Amharic names.
 */

export async function GET(request: Request, { params }: { params: Promise<{ report: string }> }): Promise<Response> {
  const { report } = await params;
  if (!(EXPORTABLE as readonly string[]).includes(report)) return new Response("No such report.", { status: 404 });
  const { token, companyId } = await getSession();
  if (!token || !companyId) return new Response("Sign in to export reports.", { status: 401 });
  const ctx = { token, companyId };
  const q = new URL(request.url).searchParams;
  const word = (n: string) => (/^[a-z_]{1,20}$/.test(q.get(n) ?? "") ? (q.get(n) as string) : undefined);
  const uuid = (n: string) => (/^[0-9a-f-]{36}$/.test(q.get(n) ?? "") ? (q.get(n) as string) : undefined);
  const id = uuid("account_id");

  try {
    const period = await getReportPeriod({ from: q.get("from") ?? undefined, to: q.get("to") ?? undefined });
    const asOf = isDate(q.get("as_of")) ? (q.get("as_of") as string) : period.to;
    const options = { kind: word("kind"), group: word("group"), account_id: id, action: word("action"), user_id: uuid("user_id") };
    const pointInTime = ["balance-sheet", "trial-balance", "ar-ageing", "ap-ageing"].includes(report);
    const { title, rows } = await exportRows(report as ExportableReport, { ...period, as_of: asOf, ...options }, ctx);
    await ledgerly.recordExport({
      report, format: "csv", ...(pointInTime ? { as_of: asOf } : { from: period.from, to: period.to }),
      options: Object.fromEntries(Object.entries(options).filter(([, v]) => v !== undefined)), rows: Math.max(0, rows.length - 1),
    }, ctx);
    const name = `${title}-${pointInTime ? asOf : `${period.from}-to-${period.to}`}.csv`.replace(/[^a-z0-9.-]/gi, "");
    return new Response(`﻿${toCsv(rows)}`, {
      headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": `attachment; filename="${name}"`, "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) return new Response(error.message, { status: error.status });
    throw error;
  }
}

import { ApiError, ledgerly } from "@/lib/api";
import { toCsv } from "@/lib/csv";
import { getReportPeriod } from "@/lib/data";
import { asOfLabel, rangeLabel } from "@/lib/periods";
import { EXPORTABLE, ON_A_BASIS, exportRows, reportTitle, type ExportableReport } from "@/lib/report-exports";
import { exportFileName, footerRows, headerRows, preparedLabel, type ReportIdentity } from "@/lib/reportHeader";
import { isDate } from "@/lib/reports";
import { getSession } from "@/lib/session";
import { toXlsx } from "@/lib/xlsx";

/*
 * Module 10: a report as a CSV file, or with ?format=xlsx as an Excel workbook (src/lib/xlsx.ts). The report is read from
 * the API under the person's own session (so their role decides what they get), and the export is recorded in the audit
 * log before the file is handed over (who, when, which report, which period, how many rows). The file opens with the
 * report's header (company, report, period, filters) and ends with its footer (basis, currency, prepared when and by
 * whom), as on screen and on paper (lib/reportHeader.ts). A UTF-8 byte order mark lets spreadsheets read Amharic names.
 */

export async function GET(request: Request, { params }: { params: Promise<{ report: string }> }): Promise<Response> {
  const { report } = await params;
  if (!(EXPORTABLE as readonly string[]).includes(report)) return new Response("No such report.", { status: 404 });
  const { token, companyId, name: person } = await getSession();
  if (!token || !companyId) return new Response("Sign in to export reports.", { status: 401 });
  const ctx = { token, companyId };
  const q = new URL(request.url).searchParams;
  const word = (n: string) => (/^[a-z_]{1,20}$/.test(q.get(n) ?? "") ? (q.get(n) as string) : undefined);
  const uuid = (n: string) => (/^[0-9a-f-]{36}$/.test(q.get(n) ?? "") ? (q.get(n) as string) : undefined);
  const id = uuid("account_id");
  const format = q.get("format") === "xlsx" ? "xlsx" : "csv";

  try {
    // The period as the page had it: a preset ("All dates", "Last month") or dates (lib/periodParams.ts).
    const period = await getReportPeriod(Object.fromEntries(q.entries()));
    const pointInTime = ["balance-sheet", "trial-balance", "ar-ageing", "ap-ageing", "stock-ageing", "inventory-valuation", "cash-forecast"].includes(report);
    const asOf = isDate(q.get("as_of")) ? (q.get("as_of") as string) : (period.picked.to ?? period.ctx.today);
    const options = { kind: word("kind"), group: word("group"), account_id: id, action: word("action"), user_id: uuid("user_id"), warehouse_id: uuid("warehouse_id"),
      rows: word("rows"), columns: word("columns"), measure: word("measure"), account_type: word("account_type"),
      project_id: uuid("project_id"), location_id: uuid("location_id"), class_id: uuid("class_id"),
      // A transaction report's Customize choices (each checked against its own list in lib/transactionColumns.ts).
      cols: /^[a-z_,]{1,400}$/.test(q.get("cols") ?? "") ? (q.get("cols") as string) : undefined, sort: word("sort"), dir: word("dir"), status: word("status"),
      // The ageing's view and periods (lib/ageing.ts checks the ranges).
      view: word("view"), source: word("source"), by: word("by"), pct: q.get("pct") === "1" ? "1" : undefined,
      // The profit and loss as the page had it: its comparison and basis; an open period ("All dates") has none to compare with.
      compare: ["year", "quarter", "month"].includes(q.get("compare") ?? "") ? (q.get("compare") as string) : undefined, basis: q.get("basis") === "cash" ? "cash" : undefined,
      open: period.picked.from && period.picked.to ? undefined : "1",
      // The cash flow method, the forecast's weeks, and a statement's customer or supplier (loaded by the API through the company).
      method: q.get("method") === "direct" ? "direct" : undefined, weeks: /^\d{1,2}$/.test(q.get("weeks") ?? "") ? (q.get("weeks") as string) : undefined,
      contact_id: uuid("contact_id"), interval: /^\d{1,3}$/.test(q.get("interval") ?? "") ? (q.get("interval") as string) : undefined,
      periods: /^\d{1,2}$/.test(q.get("periods") ?? "") ? (q.get("periods") as string) : undefined };
    const [{ rows, heading, period: periodText }, settings] = await Promise.all([
      exportRows(report as ExportableReport, { from: period.from, to: period.to, as_of: asOf, ...options, calendar: period.ctx.calendar }, ctx),
      ledgerly.companySettings(ctx).catch(() => null),
    ]);
    await ledgerly.recordExport({
      report, format, ...(pointInTime ? { as_of: asOf } : { from: period.from, to: period.to }),
      options: Object.fromEntries(Object.entries(options).filter(([, v]) => v !== undefined)), rows: Math.max(0, rows.length - 1),
    }, ctx);

    const calendar = settings?.calendar ?? period.ctx.calendar;
    const title = heading ?? reportTitle(report as ExportableReport, options);
    const identity: ReportIdentity = {
      company: settings?.trading_name || settings?.legal_name || "Evenbooks", legalName: settings?.legal_name ?? null,
      title,
      period: periodText ?? (pointInTime ? asOfLabel(asOf, calendar) : rangeLabel(period.picked, calendar)),
      basis: ON_A_BASIS.includes(report as ExportableReport) ? (q.get("basis") === "cash" ? "cash" : settings?.accounting_basis ?? "accrual") : null,
      currency: settings?.base_currency ?? null,
      prepared: preparedLabel(new Date(), settings?.timezone || "Africa/Addis_Ababa", calendar, person),
    };
    const top = headerRows(identity);
    const sheet = [...top, ...rows, ...footerRows(identity)];
    const file = exportFileName(identity, format);
    // A plain-ASCII name for old browsers, the real one (en dashes, Amharic) for the rest (RFC 6266).
    const disposition = `attachment; filename="${file.replace(/[^\x20-\x7e]/g, "-").replace(/"/g, "")}"; filename*=UTF-8''${encodeURIComponent(file)}`;
    if (format === "xlsx") {
      const workbook = toXlsx(title, sheet, top.length);
      return new Response(workbook.buffer as ArrayBuffer, {
        headers: { "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "Content-Disposition": disposition, "Cache-Control": "no-store" },
      });
    }
    return new Response(`﻿${toCsv(sheet)}`, {
      headers: { "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": disposition, "Cache-Control": "no-store" },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status >= 400 && error.status < 500) return new Response(error.message, { status: error.status });
    throw error;
  }
}

"use server";

import { ledgerly } from "@/lib/api";
import { EXPORTABLE } from "@/lib/report-exports";
import { isDate } from "@/lib/reports";
import { getSession } from "@/lib/session";

/* Module 10: records a report printed or saved as PDF in the audit log (who, when, which report and period, how many rows). */

const POINT_IN_TIME = ["balance-sheet", "trial-balance", "ar-ageing", "ap-ageing", "stock-ageing"];

export async function recordPrint(report: string, params: Record<string, string | undefined>, rows: number): Promise<void> {
  if (!(EXPORTABLE as readonly string[]).includes(report)) return;
  const { token, companyId } = await getSession();
  if (!token || !companyId) return;
  const word = (v?: string) => (v && /^[a-z_]{1,20}$/.test(v) ? v : undefined);
  const uuid = (v?: string) => (v && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);
  const options = Object.fromEntries(Object.entries({ kind: word(params.kind), group: word(params.group), account_id: uuid(params.account_id),
    action: word(params.action), user_id: uuid(params.user_id), warehouse_id: uuid(params.warehouse_id) }).filter(([, v]) => v !== undefined));
  const dates = POINT_IN_TIME.includes(report)
    ? { as_of: isDate(params.as_of) ? params.as_of : undefined }
    : { from: isDate(params.from) ? params.from : undefined, to: isDate(params.to) ? params.to : undefined };
  await ledgerly.recordExport({ report, format: "pdf", ...dates, options, rows: Math.max(0, Math.min(1_000_000, Math.floor(rows))) }, { token, companyId });
}

import { Dt } from "@/components/Dt";
import type { ReactNode } from "react";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { isDateKey, isMomentKey, withoutUnusedForeign } from "@/lib/columns";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { ExportLink, ReportsNav } from "@/app/(app)/reports/ReportsNav";
import { getReportPeriod, getTransactionReport } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { exportPeriod, periodQuery } from "@/lib/periodParams";
import { rangeLabel, type RangePreset } from "@/lib/periods";
import {
  COLUMNS, FOREIGN, GROUPS, TX_REPORTS, column, customQuery, groupRows, isMoney, pickColumns, pickGroup, pickSort, sortRows, statusLabel,
  totals, typeLabel, type TxColumn, type TxReport, type TxRow,
} from "@/lib/transactionColumns";

type Query = Record<string, string | string[] | undefined>;
const STATUSES = [["", "All but void"], ["open", "Open"], ["overdue", "Overdue"], ["paid", "Paid"], ["draft", "Draft or awaiting approval"], ["void", "Void"]] as const;

/** Where a row's number leads: the document, the payment, or the journal entry. */
function href(report: TxReport, r: TxRow): string | null {
  if (report === "transaction-list") return `/journal/${encodeURIComponent(r.id)}`;
  const path: Record<string, string> = { invoice: "invoices", bill: "bills", sales_receipt: "sales-receipts", payment_received: "payments", payment_made: "payments-made" };
  return path[r.type] ? `/${path[r.type]}/${encodeURIComponent(r.id)}` : null;
}

function cell(key: TxColumn, r: TxRow): ReactNode {
  const v = r[key];
  if (v === null || v === "") return "";
  if (isDateKey(key) || isMomentKey(key)) return <Dt v={String(v)} time={isMomentKey(key)} />;
  if (key === "type") return typeLabel(String(v));
  if (key === "status") return statusLabel(String(v));
  if (isMoney(key)) return formatAmount(String(v));
  return String(v);
}

/**
 * A transaction report as QuickBooks shows one: its period (every preset), its header and footer, and Customize:
 * which columns and in what order, how sorted and grouped, which statuses. Every choice is in the URL, so the report
 * is printed, exported and saved as shown. Works without JavaScript (plain links and GET forms).
 */
export async function TransactionReportView({ report, q }: { report: TxReport; q: Query }) {
  const def = TX_REPORTS[report];
  const period = await getReportPeriod(q, def.fallback as RangePreset);
  const status = def.statuses && typeof q.status === "string" && STATUSES.some(([s]) => s === q.status) ? q.status : "";
  const data = await getTransactionReport({ report: def.api, from: period.picked.from ?? undefined, to: period.picked.to ?? undefined, ...(status ? { status } : {}) });
  const cols = withoutUnusedForeign(pickColumns(q, def.columns), data?.rows ?? [], q, FOREIGN);
  const sort = pickSort(q, cols);
  const group = pickGroup(q, def.group);
  const rows = data ? sortRows(data.rows, sort.sort, sort.dir) : [];
  const groups = groupRows(rows, group);
  const chosen = customQuery(cols, sort, group, { status: status || undefined });
  const here = (extra: Record<string, string>) => `/reports/${report}?${new URLSearchParams({ ...periodQuery(period.picked), ...chosen, ...extra })}`;
  const sortLink = (key: TxColumn) => here({ sort: key, dir: sort.sort === key && sort.dir === "asc" ? "desc" : "asc" });
  const total = (rs: TxRow[]) => totals(rs, cols);

  return (
    <>
      <PageTitle eyebrow="Reports / Transactions" title={def.title} actions={<ExportLink report={report} params={{ ...exportPeriod(period.picked), ...chosen }} />} />
      <DateRangeFilter picked={period.picked} keep={{ ...chosen }} fields={["status"]}>
        {def.statuses && (
          <div><label className="field-label" htmlFor="status">Status</label>
            <select className="field" id="status" name="status" defaultValue={status}>{STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
        )}
      </DateRangeFilter>

      <ColumnCustomizer defs={COLUMNS} cols={cols} keep={{ ...periodQuery(period.picked), status: status || undefined }}
        moveHref={(next) => here({ cols: next.join(",") })} resetHref={`/reports/${report}?${new URLSearchParams(periodQuery(period.picked))}`}
        summary="Customize: columns, sort and grouping">
        <div><label className="field-label" htmlFor="sort">Sort by</label>
          <select className="field" id="sort" name="sort" defaultValue={sort.sort}>{COLUMNS.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}</select></div>
        <div><label className="field-label" htmlFor="dir">Order</label>
          <select className="field" id="dir" name="dir" defaultValue={sort.dir}><option value="asc">Ascending</option><option value="desc">Descending</option></select></div>
        <div><label className="field-label" htmlFor="group">Group by</label>
          <select className="field" id="group" name="group" defaultValue={group}>{GROUPS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
      </ColumnCustomizer>

      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current={`/reports/${report}`} />
        <Card className="!p-2 min-w-0 flex-[4_1_560px]" label={def.title}>
          {!data ? <p className="p-3 text-[14px] text-muted">Not available in the demo or for your role.</p> : (
            <>
              <div className="px-3 pt-3">
                <ReportHeader title={def.title} period={rangeLabel(period.picked, period.ctx)}
                  filters={[status ? `Status: ${STATUSES.find(([s]) => s === status)?.[1]}` : null, group !== "none" ? `Grouped by ${GROUPS.find(([g]) => g === group)?.[1].toLowerCase()}` : null]} />
              </div>
              {data.truncated && <p role="status" className="mx-3 mb-3 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">The first 5,000 transactions are shown: choose a shorter period.</p>}
              <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={def.title}>
                <table className="tbl">
                  <thead>
                    <tr>{cols.map((c) => (
                      <th key={c} scope="col" className={isMoney(c) || c === "days_past_due" || c === "exchange_rate" ? "!text-right" : ""}
                        aria-sort={sort.sort === c ? (sort.dir === "asc" ? "ascending" : "descending") : undefined}>
                        <Link href={sortLink(c)} className="hover:underline">{column(c).label}{sort.sort === c ? (sort.dir === "asc" ? " ▲" : " ▼") : ""}</Link>
                      </th>
                    ))}</tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 && <tr><td colSpan={cols.length} className="py-8 text-center text-muted">Nothing in this period.</td></tr>}
                    {groups.map((g) => (
                      <GroupRows key={g.key} label={g.label} rows={g.rows} cols={cols} report={report} subtotal={group !== "none" ? total(g.rows) : null} />
                    ))}
                    {rows.length > 0 && (
                      <tr className="font-extrabold">
                        {cols.map((c, i) => <td key={c} className={isMoney(c) ? "num text-right" : ""}>{i === 0 ? "Total" : total(rows)[c] !== undefined ? formatAmount(total(rows)[c]!) : ""}</td>)}
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              <ReportFooter basis={null} currency={data.currency} />
            </>
          )}
        </Card>
      </div>
    </>
  );
}

function GroupRows({ label, rows, cols, report, subtotal }: { label: string; rows: TxRow[]; cols: TxColumn[]; report: TxReport; subtotal: Partial<Record<TxColumn, string>> | null }) {
  return (
    <>
      {subtotal && <tr><th scope="rowgroup" colSpan={cols.length} className="!pt-4 text-left !text-[13px] !text-ink">{label}</th></tr>}
      {rows.map((r) => (
        <tr key={`${r.type}-${r.id}`}>
          {cols.map((c) => {
            const text = cell(c, r);
            const link = c === "number" ? href(report, r) : null;
            return (
              <td key={c} className={isMoney(c) || c === "days_past_due" || c === "exchange_rate" ? "num text-right" : c === "date" || c === "due_date" ? "num" : ""}>
                {link && text ? <Link href={link} className="hover:underline">{text}</Link> : text}
              </td>
            );
          })}
        </tr>
      ))}
      {subtotal && (
        <tr className="font-semibold">
          {cols.map((c, i) => <td key={c} className={isMoney(c) ? "num text-right" : ""}>{i === 0 ? `Total ${label}` : subtotal[c] !== undefined ? formatAmount(subtotal[c]!) : ""}</td>)}
        </tr>
      )}
    </>
  );
}

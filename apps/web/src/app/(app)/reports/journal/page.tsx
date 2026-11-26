import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { chooseColumns, rightAligned, withoutUnusedForeign } from "@/lib/columns";
import { getJournalReport, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { parse, toString } from "@/lib/decimal";
import { JOURNAL_COLUMNS, JOURNAL_DEFAULTS, JOURNAL_FOREIGN, journalEntries, transactionHref, type JournalColumn, type JournalRow } from "@/lib/ledgerColumns";
import { exportPeriod, periodQuery } from "@/lib/periodParams";
import { rangeLabel } from "@/lib/periods";
import { typeLabel } from "@/lib/transactionColumns";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Journal report" };

const SOURCES = [["", "Every kind of entry"], ["manual", "Manual journals"], ["invoice", "Invoices"], ["credit_note", "Credit notes"], ["bill", "Bills"],
  ["expense", "Expenses"], ["payment", "Payments"], ["transfer", "Transfers"], ["deposit", "Deposits"], ["stock_adjustment", "Stock adjustments"],
  ["reversal", "Reversals"], ["opening", "Opening balances"], ["closing", "Year-end close"], ["adjustment", "Audit adjustments"]] as const;
const MONEY = new Set<JournalColumn>(["debit", "credit", "amount", "foreign_amount"]);
const def = (c: JournalColumn) => JOURNAL_COLUMNS.find((d) => d.key === c)!;

function cell(c: JournalColumn, r: JournalRow) {
  const v = r[c];
  if (v === null || v === "") return "";
  if (MONEY.has(c)) return v === "0.00" && (c === "debit" || c === "credit") ? "" : formatAmount(v);
  if (c === "type") return typeLabel(v);
  if (c === "entry") return <Link href={`/journal/${encodeURIComponent(r.entry_id)}`} className="hover:underline">{v}</Link>;
  if (c === "number") {
    const href = transactionHref(r);
    return href ? <Link href={href} className="hover:underline">{v}</Link> : v;
  }
  return v;
}

/**
 * Module 6: the Journal report, as QuickBooks shows it: every entry of a period with all its lines (Date, Transaction
 * type, No., Name, Memo, Account, Debit, Credit), each entry totalled, debits equal to credits. Customize adds the
 * journal number, due date, amount, foreign amount, currency, rate, location, class, project, created and posted by.
 */
export default async function JournalReportPage({ searchParams }: PageProps<"/reports/journal">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "this_month");
  const source = typeof q.source === "string" && SOURCES.some(([s]) => s === q.source) ? q.source : "";
  const report = await getJournalReport({ from: period.picked.from ?? undefined, to: period.picked.to ?? undefined, ...(source ? { source } : {}) });
  const entries = report ? journalEntries(report.lines) : [];
  const cols = withoutUnusedForeign(chooseColumns(q, JOURNAL_COLUMNS, JOURNAL_DEFAULTS), report?.lines.map((l) => ({ currency: l.currency ?? null })) ?? [], q, JOURNAL_FOREIGN);
  const custom = Boolean(q.cols) || q.customize === "1";
  const keep = { ...periodQuery(period.picked), source: source || undefined };
  const href = (extra: Record<string, string | undefined>) =>
    `/reports/journal?${new URLSearchParams(Object.entries({ ...keep, ...extra }).filter(([, v]) => v) as [string, string][])}`;
  const align = (c: JournalColumn) => (rightAligned(def(c).kind) ? "num text-right" : c === "date" || c === "due_date" ? "num" : "");
  // Decimal strings throughout (lib/decimal.ts), never floats.
  const all = { debits: toString(entries.reduce((t, e) => t + parse(e.debits), 0n)), credits: toString(entries.reduce((t, e) => t + parse(e.credits), 0n)) };

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Ledger</>} title="Journal report"
        actions={<ExportLink report="journal" params={{ ...exportPeriod(period.picked), source: source || undefined, cols: custom ? cols.join(",") : undefined }} />} />
      <DateRangeFilter picked={period.picked} keep={{ cols: custom ? cols.join(",") : undefined }} fields={["source"]}>
        <div>
          <label className="field-label" htmlFor="source">Entries</label>
          <select className="field" id="source" name="source" defaultValue={source}>{SOURCES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
      </DateRangeFilter>
      <ColumnCustomizer defs={JOURNAL_COLUMNS} cols={cols} keep={keep} moveHref={(next) => href({ cols: next.join(",") })} resetHref={href({})} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/journal" />
        <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Journal report">
          {!report ? <p className="p-3 text-[14px] text-muted">Not available in the demo, or the period ends before it starts.</p> : (
            <>
              <div className="px-3 pt-3">
                <ReportHeader title="Journal" period={rangeLabel(period.picked, period.ctx.calendar)} filters={[source ? SOURCES.find(([s]) => s === source)?.[1] : null]} />
              </div>
              {report.truncated && <p role="status" className="mx-3 mb-3 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Only the first 5,000 lines are shown: choose a shorter period or one kind of entry.</p>}
              <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Journal">
                <table className="tbl">
                  <thead><tr>{cols.map((c) => <th key={c} scope="col" className={align(c).includes("text-right") ? "!text-right" : ""}>{def(c).label}</th>)}</tr></thead>
                  {entries.length === 0 && <tbody><tr><td colSpan={cols.length} className="py-8 text-center text-muted">Nothing posted in this period.</td></tr></tbody>}
                  {entries.map((e) => (
                    <tbody key={e.entryId}>
                      {e.rows.map((r) => <tr key={r.id}>{cols.map((c) => <td key={c} className={align(c)}>{cell(c, r)}</td>)}</tr>)}
                      <tr className="font-semibold">{cols.map((c) => (
                        <td key={c} className={`${align(c)} !border-b-line`}>{c === "debit" ? formatAmount(e.debits) : c === "credit" ? formatAmount(e.credits) : ""}</td>
                      ))}</tr>
                    </tbody>
                  ))}
                  {entries.length > 0 && (
                    <tfoot>
                      <tr className="border-t-2 border-ink font-bold">{cols.map((c, i) => (
                        <td key={c} className={align(c)}>{c === "debit" ? formatAmount(all.debits) : c === "credit" ? formatAmount(all.credits) : i === 0 ? "Total" : ""}</td>
                      ))}</tr>
                    </tfoot>
                  )}
                </table>
              </div>
              <ReportFooter basis={null} currency={report.currency} />
            </>
          )}
        </Card>
      </div>
    </>
  );
}

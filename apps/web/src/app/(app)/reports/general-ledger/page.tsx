import { rangeLabel } from "@/lib/periods";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "@/components/Link";
import { exportPeriod } from "@/lib/periodParams";
import { Card, PageTitle } from "@/components/ui";
import { getChart, getDimensions, getGeneralLedger, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { parse, toString } from "@/lib/decimal";
import { ExportLink } from "../ReportsNav";
import { drCr } from "@/lib/ledger";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { chooseColumns, rightAligned, withoutUnusedForeign } from "@/lib/columns";
import { GL_COLUMNS, GL_DEFAULTS, GL_FOREIGN, glRow, transactionHref, type GlColumn, type GlRow } from "@/lib/ledgerColumns";
import { periodQuery } from "@/lib/periodParams";
import { typeLabel } from "@/lib/transactionColumns";

export const metadata = { title: "General ledger" };

/** Debits less credits, as a decimal string. */
const net = (debits: string, credits: string) => toString(parse(debits) - parse(credits));

const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

function Balance({ value }: { value: string }) {
  const b = drCr(value);
  return <>{formatAmount(b.amount)}{b.side && <span className="ml-1 text-[12px] text-muted">{b.side}</span>}</>;
}

const def = (c: GlColumn) => GL_COLUMNS.find((d) => d.key === c)!;
const MONEY = new Set<GlColumn>(["debit", "credit", "amount", "foreign_amount"]);

function cell(c: GlColumn, r: GlRow) {
  const v = r[c];
  if (v === null || v === "") return "";
  if (c === "balance") return <Balance value={r.balance} />;
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
 * Module 6: every posted line of a period, account by account, with the balance carried forward and a running balance,
 * in QuickBooks' columns (Date, Transaction type, No., Name, Memo, Split, Debit, Credit, Balance) or the ones the
 * reader picks under Customize: due date, journal number, foreign amount, currency and rate, location, class, project,
 * created and posted by.
 */
export default async function GeneralLedgerPage({ searchParams }: PageProps<"/reports/general-ledger">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  const filters = {
    from: period.from, to: period.to,
    account_id: uuid(q.account_id), location_id: uuid(q.location_id), class_id: uuid(q.class_id), project_id: uuid(q.project_id),
  };
  const [gl, chart, dimensions] = await Promise.all([getGeneralLedger(filters), getChart(true), getDimensions()]);
  const kinds = [["location", "location_id", "Location"], ["class", "class_id", "Class"], ["project", "project_id", "Project"]] as const;
  const lines = gl?.accounts.flatMap((a) => a.lines) ?? [];
  const cols = withoutUnusedForeign(chooseColumns(q, GL_COLUMNS, GL_DEFAULTS), lines.map((l) => ({ currency: l.currency ?? null })), q, GL_FOREIGN);
  const custom = Boolean(q.cols) || q.customize === "1";
  const keep = { ...periodQuery(period.picked), account_id: filters.account_id, location_id: filters.location_id, class_id: filters.class_id, project_id: filters.project_id };
  const href = (extra: Record<string, string | undefined>) =>
    `/reports/general-ledger?${new URLSearchParams(Object.entries({ ...keep, ...extra }).filter(([, v]) => v) as [string, string][])}`;
  const align = (c: GlColumn) => (rightAligned(def(c).kind) || c === "balance" ? "num text-right" : c === "date" || c === "due_date" ? "num" : "");

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Ledger</>} title="General ledger"
        actions={<ExportLink report="general-ledger" params={{ ...exportPeriod(period.picked), account_id: filters.account_id, location_id: filters.location_id, class_id: filters.class_id, project_id: filters.project_id,
          cols: custom ? cols.join(",") : undefined }} />} />
      <DateRangeFilter picked={period.picked} keep={{ ...q, cols: custom ? cols.join(",") : undefined, col: undefined, customize: undefined }} fields={["account_id", "location_id", "class_id", "project_id"]}>
        <div className="min-w-[220px]">
          <label className="field-label" htmlFor="account_id">Account</label>
          <select className="field" id="account_id" name="account_id" defaultValue={filters.account_id ?? ""}>
            <option value="">Every account</option>
            {chart.data.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
          </select>
        </div>
        {kinds.filter(([kind]) => dimensions.some((d) => d.kind === kind)).map(([kind, column, label]) => (
          <div key={kind}>
            <label className="field-label" htmlFor={column}>{label}</label>
            <select className="field" id={column} name={column} defaultValue={filters[column] ?? ""}>
              <option value="">All</option>
              {dimensions.filter((d) => d.kind === kind).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
        ))}
      </DateRangeFilter>
      <ColumnCustomizer defs={GL_COLUMNS} cols={cols} keep={keep} moveHref={(next) => href({ cols: next.join(",") })} resetHref={href({})} />
      {!gl && <p className="text-[14px] text-muted">{chart.demo ? "Not available in the demo." : "Check the dates: the period ends on or after it starts."}</p>}
      {gl?.truncated && <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Only the first lines of a long period are shown: choose a shorter period or one account.</p>}
      {gl && gl.accounts.length === 0 && <p className="text-[14px] text-muted">Nothing posted in this period.</p>}
      {gl && gl.accounts.length > 0 && <ReportHeader title="General ledger" period={rangeLabel(period.picked, period.ctx.calendar)}
        filters={[filters.account_id ? chart.data.find((c) => c.id === filters.account_id)?.name : "Every account"]} />}
      {gl?.accounts.map((a) => (
        <Card key={a.account_id} className="mb-4 !p-2" label={`${a.code} ${a.name}`}>
          <div className="flex flex-wrap items-center justify-between gap-2 px-3 pt-2">
            <h2 className="text-[15px] font-bold"><Link href={`/accounts/${encodeURIComponent(a.account_id)}`} className="hover:underline"><span className="num">{a.code}</span> {a.name}</Link></h2>
            {a.lines.length > 0 && (
              <Link href={`/journal/reclassify?${new URLSearchParams({ account_id: a.account_id, from: gl.from, to: gl.to }).toString()}`} className="text-[13px] font-semibold text-brand hover:underline print:hidden">
                Reclassify lines
              </Link>
            )}
          </div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr>{cols.map((c) => <th key={c} scope="col" className={align(c).includes("text-right") ? "!text-right" : ""}>{def(c).label}</th>)}</tr></thead>
              <tbody>
                <tr className="font-semibold">{cols.map((c, i) => (
                  <td key={c} className={align(c)}>{c === "balance" ? <Balance value={a.opening} /> : i === 0 ? `Brought forward at ${gl.from}` : ""}</td>
                ))}</tr>
                {a.lines.map((l) => {
                  const r = glRow(l);
                  return <tr key={r.id}>{cols.map((c) => <td key={c} className={align(c)}>{cell(c, r)}</td>)}</tr>;
                })}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-ink font-bold">{cols.map((c, i) => (
                  <td key={c} className={align(c)}>
                    {c === "balance" ? <Balance value={a.closing} /> : c === "debit" ? formatAmount(a.debits) : c === "credit" ? formatAmount(a.credits)
                      : c === "amount" ? formatAmount(net(a.debits, a.credits)) : i === 0 ? `Balance at ${gl.to}` : ""}
                  </td>
                ))}</tr>
              </tfoot>
            </table>
          </div>
        </Card>
      ))}
      {gl && gl.accounts.length > 0 && <ReportFooter />}
    </>
  );
}

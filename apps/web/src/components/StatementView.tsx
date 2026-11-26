import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { chooseColumns, rightAligned, withoutUnusedForeign } from "@/lib/columns";
import { getCustomerStatement, getReportPeriod, getSupplierStatement } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { STATEMENT_DEFAULTS, STATEMENT_FOREIGN, statementColumns, statementRow, transactionHref, type StatementColumn, type StatementRow } from "@/lib/ledgerColumns";
import { periodQuery } from "@/lib/periodParams";
import { rangeLabel } from "@/lib/periods";
import { typeLabel } from "@/lib/transactionColumns";

type Query = Record<string, string | string[] | undefined>;
const MONEY = new Set<StatementColumn>(["charge", "payment", "balance", "open_balance", "foreign_amount"]);

/**
 * A customer's or supplier's statement for a period: the balance brought forward, every movement on the receivable
 * (or payable) account with a running balance, and the balance at the end. QuickBooks' columns by default (date,
 * transaction type, number, memo, due date, charges, payments, balance), or the ones picked under Customize: journal
 * number, open balance, foreign amount, currency and rate, created by. Foreign columns show only when a line has one.
 */
export async function StatementView({ party, id, q }: { party: "customer" | "supplier"; id: string; q: Query }) {
  const payable = party === "supplier";
  const period = await getReportPeriod(q, "last_90_days");
  const range = { from: period.from, to: period.to };
  const data = payable ? await getSupplierStatement(id, range) : await getCustomerStatement(id, range);
  if (!data) notFound();
  const who = "supplier" in data ? data.supplier : data.customer;
  const defs = statementColumns(payable);
  const rows = data.lines.map((l, i) => statementRow(l, i, payable));
  const cols = withoutUnusedForeign(chooseColumns(q, defs, STATEMENT_DEFAULTS), rows, q, STATEMENT_FOREIGN);
  const custom = Boolean(q.cols) || q.customize === "1";
  const base = `/${party === "supplier" ? "suppliers" : "customers"}/${encodeURIComponent(id)}`;
  const keep = periodQuery(period.picked);
  const href = (extra: Record<string, string | undefined>) =>
    `${base}/statement?${new URLSearchParams(Object.entries({ ...keep, ...extra }).filter(([, v]) => v) as [string, string][])}`;
  const def = (c: StatementColumn) => defs.find((d) => d.key === c)!;
  const align = (c: StatementColumn) => (rightAligned(def(c).kind) ? "num text-right" : c === "date" || c === "due_date" ? "num" : "");
  const cell = (c: StatementColumn, r: StatementRow) => {
    const v = r[c];
    if (v === null || v === "") return "";
    if (c === "type") return typeLabel(v);
    if (MONEY.has(c)) return v === "0.00" && (c === "charge" || c === "payment") ? "" : formatAmount(v);
    if (c === "number" || c === "entry") {
      const link = c === "entry" ? (r.entry_id ? `/journal/${encodeURIComponent(r.entry_id)}` : null) : transactionHref(r);
      return link ? <Link href={link} className="hover:underline">{v}</Link> : v;
    }
    return v;
  };
  const title = payable ? "Supplier statement" : "Customer statement";

  return (
    <>
      <PageTitle eyebrow={<><Link href={base} className="hover:underline">{who.name}</Link> / Statement</>}
        title={<>Statement · <span className="num">{data.from}</span> to <span className="num">{data.to}</span></>} />
      <DateRangeFilter picked={period.picked} keep={{ cols: custom ? cols.join(",") : undefined }} fields={[]} />
      <ColumnCustomizer defs={defs} cols={cols} keep={keep} moveHref={(next) => href({ cols: next.join(",") })} resetHref={href({})} />
      <Card className="max-w-[1100px]" label="Statement">
        <ReportHeader title={title} period={rangeLabel(period.picked, period.ctx.calendar)} filters={[`${payable ? "Supplier" : "Customer"}: ${who.name}`, who.tax_id ? `TIN ${who.tax_id}` : null]} />
        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={title}>
          <table className="tbl">
            <thead><tr>{cols.map((c) => <th key={c} scope="col" className={align(c).includes("text-right") ? "!text-right" : ""}>{def(c).label}</th>)}</tr></thead>
            <tbody>
              <tr className="font-semibold">{cols.map((c, i) => (
                <td key={c} className={align(c)}>{c === "balance" ? formatAmount(data.opening) : i === 0 ? `Balance brought forward at ${data.from}` : ""}</td>
              ))}</tr>
              {rows.length === 0 && <tr><td colSpan={cols.length} className="py-6 text-center text-muted">Nothing in this period.</td></tr>}
              {rows.map((r) => <tr key={r.id}>{cols.map((c) => <td key={c} className={align(c)}>{cell(c, r)}</td>)}</tr>)}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-ink font-bold">{cols.map((c, i) => (
                <td key={c} className={align(c)}>{c === "balance" ? formatMoney(data.closing, data.currency) : i === 0 ? `${payable ? "We owe" : "Owes"} at ${data.to}` : ""}</td>
              ))}</tr>
            </tfoot>
          </table>
        </div>
        <ReportFooter basis={null} currency={data.currency} />
      </Card>
    </>
  );
}

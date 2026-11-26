import type { ProfitAndLossColumns } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { percentOf } from "@/lib/pnlColumns";

type Line = { values: string[]; total: string };

/**
 * The profit and loss in columns (QuickBooks' "Display columns by"): the same accounts and totals as the one-column
 * report, a column per period or per customer, supplier or tag, and a Total; with "% of income", each total's share of
 * the income next to it.
 */
export function ProfitAndLossColumnsView({ data, labels, pct }: { data: ProfitAndLossColumns; labels?: string[]; pct: boolean }) {
  const heads = data.columns.map((c, i) => labels?.[i] ?? c.label);
  const span = heads.length + 2 + (pct ? 1 : 0);
  const income = data.totals.income.total;
  const cells = (l: Line, strong = false) => (
    <>
      {l.values.map((v, i) => <td key={i} className="num text-right">{v === "0.00" && !strong ? "" : formatAmount(v)}</td>)}
      <td className="num text-right font-semibold">{formatAmount(l.total)}</td>
      {pct && <td className="num text-right text-muted">{percentOf(l.total, income)}</td>}
    </>
  );
  const section = (title: string, rows: ProfitAndLossColumns["sections"]["income"], total: Line) => (
    <>
      <tr><th scope="rowgroup" colSpan={span} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {rows.map((r) => <tr key={r.account_id}><td className="pl-7">{r.name}</td>{cells(r)}</tr>)}
      <tr className="font-bold"><td>Total {title.toLowerCase()}</td>{cells(total, true)}</tr>
    </>
  );
  const keyRow = (label: string, l: Line, dark = false) => (
    <tr className={dark ? "bg-side text-white font-extrabold" : "bg-[#f0faf7] font-extrabold"}><td>{label}</td>{cells(l, true)}</tr>
  );

  return (
    <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Profit and loss by column">
      <table className="tbl">
        <thead>
          <tr>
            <th scope="col">Account</th>
            {heads.map((h, i) => <th key={i} scope="col" className="!text-right whitespace-nowrap">{h}</th>)}
            <th scope="col" className="!text-right">Total</th>
            {pct && <th scope="col" className="!text-right">% of income</th>}
          </tr>
        </thead>
        <tbody>
          {section("Income", data.sections.income, data.totals.income)}
          {section("Cost of goods sold", data.sections.cost_of_sales, data.totals.cost_of_sales)}
          {keyRow("Gross profit", data.totals.gross_profit)}
          {section("Expenses", data.sections.expenses, data.totals.expenses)}
          {keyRow("Net profit before tax", data.totals.net_profit, true)}
        </tbody>
      </table>
    </div>
  );
}

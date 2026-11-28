import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle, Segmented } from "@/components/ui";
import { parse } from "@/lib/decimal";
import { getCompanySettings, getProfitAndLoss, getProfitAndLossColumns, getReportPeriod } from "@/lib/data";
import { DISPLAY_BY, columnsRequest, percentOf, pickDisplayBy } from "@/lib/pnlColumns";
import { ProfitAndLossColumnsView } from "./ProfitAndLossColumnsView";
import { rangeLabel } from "@/lib/periods";
import { periodQuery, exportPeriod } from "@/lib/periodParams";
import { COMPARE_FIELDS, compareQuery, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { profitAndLossLines } from "@/lib/compareLines";
import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ExportLink, ReportsNav } from "./ReportsNav";

export const metadata = { title: "Profit and loss" };

/**
 * Module 10: the profit and loss for a period (the fiscal year to date by default), compared with up to three other
 * periods (the previous period by default; the same period last year or two years ago, any preset or dates of the
 * reader's own), with the change and % change against the first; or, with QuickBooks' "Display columns by", a column
 * per month, quarter or year (in the company's calendar) or per customer, location, class or project; and "% of
 * income" next to the figures.
 */
export default async function ReportsPage({ searchParams }: PageProps<"/reports">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  // Accrual (when invoiced or billed), or cash (basis=cash): when the money moved.
  const cash = q.basis === "cash";
  // Display columns by (accrual basis; comparisons are the one-column report's), and % of income.
  const by = pickDisplayBy(q.by);
  const pct = q.pct === "1";
  const split = by === "total" ? null : columnsRequest(by, { from: period.from, to: period.to }, period.ctx.calendar);
  const cmp = pickRangeComparisons(q, period.picked, period.ctx, ["previous_period"]);
  const compared = by === "total" ? cmp.periods : [];
  const diff = pickDiff(q, "pct");
  const basis = cash ? { basis: "cash" as const } : {};
  const [results, settings, columns] = await Promise.all([
    Promise.all([{ from: period.from, to: period.to }, ...compared].map((p) => getProfitAndLoss({ from: p.from, to: p.to, ...basis }))),
    getCompanySettings(),
    split ? getProfitAndLossColumns({ from: period.from, to: period.to, by: split.by, ...(split.starts ? { starts: split.starts } : {}) }) : Promise.resolve(null),
  ]);
  const shown = { ...(by !== "total" ? { by } : {}), ...(pct ? { pct: "1" } : {}), ...compareQuery(cmp.slots, diff) };
  const link = (o: { cash?: boolean }) =>
    `/reports?${new URLSearchParams({ ...periodQuery(period.picked), ...((o.cash ?? cash) ? { basis: "cash" } : {}), ...shown })}`;
  const currency = settings.base_currency;
  const t = results[0].totals;
  const lines = profitAndLossLines(results);
  const ownLabel = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [compared.length ? ownLabel : "Amount", ...compared.map((c) => c.label)];
  // No income yet (a new company) means no margin, not a division by zero.
  const margin = (num: string, den: string) => {
    const d = parse(den);
    if (d === 0n) return "—";
    const tenths = (parse(num) * 1000n) / d;
    const abs = tenths < 0n ? -tenths : tenths;
    return `${tenths < 0n ? "−" : ""}${abs / 10n}.${abs % 10n}%`;
  };

  return (
    <>
      <PageTitle title="Profit and loss"
        actions={<ExportLink report="profit-and-loss" params={{ ...exportPeriod(period.picked), ...shown, ...(cash ? { basis: "cash" } : {}) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["by", "pct", "compare", ...COMPARE_FIELDS]}>
        <div>
          <label className="field-label" htmlFor="by">Display columns by</label>
          <select className="field" id="by" name="by" defaultValue={by}>{DISPLAY_BY.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
        <label className="flex h-[42px] items-center gap-2 text-sm"><input type="checkbox" name="pct" value="1" defaultChecked={pct} className="size-4" /> % of income</label>
        {by === "total" && <CompareFilter slots={cmp.slots} diff={diff} />}
      </DateRangeFilter>
      {by === "total" && <div className="mb-4 flex flex-wrap gap-x-3">
        <Segmented label="Basis" size="sm" className="!mb-2" items={[
          { key: "accrual", href: link({ cash: false }), label: "Accrual", active: !cash },
          { key: "cash", href: link({ cash: true }), label: "Cash basis", active: cash },
        ]} />
      </div>}
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports" />
        <Card className="min-w-0 flex-[4_1_560px]" label="Profit and loss statement">
          <ReportHeader title={by !== "total" ? `Profit and loss by ${DISPLAY_BY.find(([k]) => k === by)![1].toLowerCase()}` : cash ? "Profit and loss (cash basis)" : "Profit and loss"}
            period={rangeLabel(period.picked, period.ctx)} filters={[compared.length ? `Compared with ${compared.map((c) => c.label).join(", ")}` : null]} />
          {by !== "total" && (columns ? <ProfitAndLossColumnsView data={columns} labels={split?.labels} pct={pct} />
            : <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">More than 60 columns: choose a shorter period or longer columns.</p>)}
          {by === "total" && <>
            {cash && <p className="mb-3 text-center text-[12px] text-muted">Income and expenses when the money moved: invoices and bills as they are paid, cash sales and expenses on their day.</p>}
            <CompareTable lines={lines} headers={headers} diff={diff}
              extra={pct ? { header: "% of income", value: (l) => (l.values[0] === null ? "—" : percentOf(l.values[0], t.income.current)) } : undefined} />
          </>}
          <p className="mt-3 text-[13px] text-muted">Read from the ledger. A closed year still shows the profit it made: closing entries are left out.{by !== "total" ? " Columns are on the accrual basis." : ""}</p>
          <ReportFooter basis={cash && by === "total" ? "cash" : undefined} currency={currency} />
        </Card>
        <aside className="flex min-w-0 flex-[1_1_240px] flex-col gap-4">
          <Card><div className="text-[13px] text-muted">Gross margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.gross_profit.current, t.income.current)}</div></Card>
          <Card><div className="text-[13px] text-muted">Net margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.net_profit.current, t.income.current)}</div></Card>
        </aside>
      </div>
    </>
  );
}

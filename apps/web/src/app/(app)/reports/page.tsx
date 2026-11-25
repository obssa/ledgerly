import { Card, PageTitle, Segmented } from "@/components/ui";
import type { ProfitAndLossRow } from "@/lib/api";
import { parse } from "@/lib/decimal";
import { getCompanySettings, getProfitAndLoss, getReportPeriod, getShellContext } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel, previousPeriod, samePeriodLastYear } from "@/lib/reports";
import { rangeLabel } from "@/lib/periods";
import { periodQuery } from "@/lib/periodParams";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ExportLink, ReportsNav } from "./ReportsNav";

export const metadata = { title: "Profit and loss" };

function change(current: string, previous: string | null): string {
  if (!previous) return "—";
  const p = parse(previous);
  if (p === 0n) return "—";
  const c = parse(current);
  // percentage with one decimal, exact: (c - p) / |p| * 100
  const tenths = ((c - p) * 1000n) / (p < 0n ? -p : p);
  const sign = tenths >= 0n ? "+" : "−";
  const abs = tenths < 0n ? -tenths : tenths;
  return `${sign}${abs / 10n}.${abs % 10n}%`;
}

/** Module 10: the profit and loss for a period (the fiscal year to date by default), against the period before it. */
export default async function ReportsPage({ searchParams }: PageProps<"/reports">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  // Against the period just before, or (compare=year) the same period last year.
  const lastYear = q.compare === "year";
  // An open period (All dates, Since 30 days ago) has no period before it to compare with.
  const closed = Boolean(period.picked.from && period.picked.to);
  const before = closed ? (lastYear ? samePeriodLastYear(period.from, period.to) : previousPeriod(period.from, period.to)) : null;
  // Accrual (when invoiced or billed), or cash (basis=cash): when the money moved.
  const cash = q.basis === "cash";
  const [pl, { companyName }, settings] = await Promise.all([
    getProfitAndLoss({ from: period.from, to: period.to, ...(before ? { compare_from: before.from, compare_to: before.to } : {}), ...(cash ? { basis: "cash" as const } : {}) }), getShellContext(), getCompanySettings(),
  ]);
  const link = (o: { year?: boolean; cash?: boolean }) =>
    `/reports?${new URLSearchParams({ ...periodQuery(period.picked), ...((o.year ?? lastYear) ? { compare: "year" } : {}), ...((o.cash ?? cash) ? { basis: "cash" } : {}) })}`;
  const currency = settings.base_currency;
  const t = pl.totals;
  // No income yet (a new company) means no margin, not a division by zero.
  const margin = (num: string, den: string) => {
    const d = parse(den);
    if (d === 0n) return "—";
    const tenths = (parse(num) * 1000n) / d;
    const abs = tenths < 0n ? -tenths : tenths;
    return `${tenths < 0n ? "−" : ""}${abs / 10n}.${abs % 10n}%`;
  };

  const section = (title: string, rows: ProfitAndLossRow[], total: { current: string; previous: string | null }) => (
    <>
      <tr><th scope="rowgroup" colSpan={4} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {rows.map((r) => (
        <tr key={r.code}>
          <td className="pl-7">{r.name}</td>
          <td className="num text-right">{formatAmount(r.current, currency)}</td>
          <td className="num text-right">{r.previous ? formatAmount(r.previous, currency) : "—"}</td>
          <td className="num text-right">{change(r.current, r.previous)}</td>
        </tr>
      ))}
      <tr className="font-bold">
        <td>Total {title.toLowerCase()}</td>
        <td className="num text-right">{formatAmount(total.current, currency)}</td>
        <td className="num text-right">{total.previous ? formatAmount(total.previous, currency) : "—"}</td>
        <td className="num text-right">{change(total.current, total.previous)}</td>
      </tr>
    </>
  );

  const keyRow = (label: string, v: { current: string; previous: string | null }, dark = false) => (
    <tr className={dark ? "bg-side text-white font-extrabold" : "bg-[#f0faf7] font-extrabold"}>
      <td>{label}</td>
      <td className="num text-right">{formatAmount(v.current, currency)}</td>
      <td className="num text-right">{v.previous ? formatAmount(v.previous, currency) : "—"}</td>
      <td className="num text-right">{change(v.current, v.previous)}</td>
    </tr>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Profit and loss" actions={<ExportLink report="profit-and-loss" params={{ from: period.from, to: period.to }} />} />
      <DateRangeFilter picked={period.picked} keep={q} />
      <div className="mb-4 flex flex-wrap gap-x-3">
        <Segmented label="Compare with" size="sm" className="!mb-2" items={[
          { key: "before", href: link({ year: false }), label: "Against the period before", active: !lastYear },
          { key: "year", href: link({ year: true }), label: "Against the same period last year", active: lastYear },
        ]} />
        <Segmented label="Basis" size="sm" className="!mb-2" items={[
          { key: "accrual", href: link({ cash: false }), label: "Accrual", active: !cash },
          { key: "cash", href: link({ cash: true }), label: "Cash basis", active: cash },
        ]} />
      </div>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports" />
        <Card className="min-w-0 flex-[4_1_560px]" label="Profit and loss statement">
          <div className="mb-3 text-center">
            <div className="text-[17px] font-extrabold">{companyName}</div>
            <div className="text-[13px] text-muted">Profit and loss{cash ? " on the cash basis" : ""} · {rangeLabel(period.picked, period.ctx.calendar)} · {currency}</div>
            {cash && <div className="mt-1 text-[12px] text-muted">Income and expenses when the money moved: invoices and bills as they are paid, cash sales and expenses on their day.</div>}
          </div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">This period</th><th scope="col" className="!text-right">{before ? periodLabel(before.from, before.to) : "No comparison"}</th><th scope="col" className="!text-right">Change</th></tr></thead>
              <tbody>
                {section("Income", pl.sections.income, t.income)}
                {section("Cost of goods sold", pl.sections.cost_of_sales, t.cost_of_sales)}
                {keyRow("Gross profit", t.gross_profit)}
                {section("Expenses", pl.sections.expenses, t.expenses)}
                {keyRow("Net profit before tax", t.net_profit, true)}
                {t.other_comprehensive_income && t.total_comprehensive_income && (Number(t.other_comprehensive_income.current) !== 0 || Number(t.other_comprehensive_income.previous ?? 0) !== 0) && <>
                  {keyRow("Other comprehensive income: revaluation surplus", t.other_comprehensive_income)}
                  {keyRow("Total comprehensive income", t.total_comprehensive_income, true)}
                </>}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-[13px] text-muted">Read from the ledger. A closed year still shows the profit it made: closing entries are left out.</p>
        </Card>
        <aside className="flex min-w-0 flex-[1_1_240px] flex-col gap-4">
          <Card><div className="text-[13px] text-muted">Gross margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.gross_profit.current, t.income.current)}</div></Card>
          <Card><div className="text-[13px] text-muted">Net margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.net_profit.current, t.income.current)}</div></Card>
        </aside>
      </div>
    </>
  );
}

import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { measureLines } from "@/lib/compareLinesMeasure";
import { COMPARE_FIELDS, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { rangeLabel } from "@/lib/periods";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getTagReport } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { getReportPeriod } from "@/lib/data";
import { ReportsNav } from "../ReportsNav";

export const metadata = { title: "Profit by tag" };

/** Module 1 (094): income, expenses and profit of the transactions each tag is on, by account. */
export default async function ProfitByTagPage({ searchParams }: PageProps<"/reports/by-tag">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  const cmp = pickRangeComparisons(q, period.picked, period.ctx);
  const diff = pickDiff(q, "pct");
  const own = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [own, ...cmp.periods.map((p) => p.label)];
  const comparedWith = cmp.periods.length ? `Compared with ${cmp.periods.map((p) => p.label).join(", ")}` : null;
  const [r, ...others] = await Promise.all([{ from: period.from, to: period.to }, ...cmp.periods].map((x) => getTagReport(x.from, x.to)));
  // A transaction with two tags counts under each: no total across tags.
  const lines = cmp.periods.length && r && others.every(Boolean)
    ? measureLines([r, ...others] as NonNullable<typeof r>[], (x) => x.data, (t) => t.id, (t) => t.profit, (t) => t.name)
    : null;

  return (
    <>
      <PageTitle title="Profit by tag" actions={<Link href="/tags" className="btn">Manage tags</Link>} />
      <DateRangeFilter picked={period.picked} keep={q} fields={COMPARE_FIELDS}>
        <CompareFilter slots={cmp.slots} diff={diff} />
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/by-tag" />
        <div className="min-w-0 flex-[4_1_560px]">
          {!r ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : r.data.length === 0 ? (
            <Card label="Profit by tag"><p className="text-sm text-muted">No tags yet. Add them under Tags, then put them on invoices, bills, expenses and journal entries.</p></Card>
          ) : (<>
            <ReportHeader title="Profit by tag" period={rangeLabel(period.picked, period.ctx)} filters={[lines ? "Profit" : null, comparedWith]} />
            {lines && <Card className="mb-4 !p-2" label="Profit by tag, compared"><CompareTable first="Tag" lines={lines} headers={headers} diff={diff} /></Card>}
            {!lines && r.data.map((t) => (
            <Card key={t.id} className="mb-4 !p-2" label={t.name}>
              <div className="flex flex-wrap items-baseline justify-between gap-2 px-3 pt-2">
                <h2 className="text-[15px] font-bold">{t.name}{!t.is_active && <span className="ml-1 text-[12px] font-normal text-muted">(off)</span>}</h2>
                <span className="text-[13px] text-muted">{t.transactions} transaction{t.transactions === 1 ? "" : "s"}</span>
              </div>
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">Amount</th></tr></thead>
                  <tbody>
                    {t.accounts.map((a) => (
                      <tr key={a.account_id}><td><span className="num">{a.code}</span> {a.name}</td><td className="num text-right">{formatAmount(a.amount)}<span className="ml-1 text-[12px] text-muted">{a.type === "income" ? "in" : "out"}</span></td></tr>
                    ))}
                    <tr><td>Income</td><td className="num text-right">{formatAmount(t.income)}</td></tr>
                    <tr><td>Expenses</td><td className="num text-right">{formatAmount(t.expenses)}</td></tr>
                    <tr className="font-semibold"><td>Profit</td><td className={`num text-right ${Number(t.profit) < 0 ? "text-bad" : ""}`}>{formatAmount(t.profit)}</td></tr>
                  </tbody>
                </table>
              </div>
            </Card>
            ))}
            <ReportFooter basis={null} />
          </>)}
          <p className="text-[13px] text-muted">From the ledger: a tagged document&rsquo;s own entries (a corrected one included), tagged journal entries, and their reversals. A transaction with two tags counts under each.</p>
        </div>
      </div>
    </>
  );
}

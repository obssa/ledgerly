import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getCashFlow, getDirectCashFlow, getReportPeriod } from "@/lib/data";
import { rangeLabel } from "@/lib/periods";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { COMPARE_FIELDS, compareQuery, periodColumnLabel, pickDiff, pickRangeComparisons } from "@/lib/compare";
import { cashFlowLines, directCashFlowLines } from "@/lib/compareLines";
import { periodQuery, exportPeriod } from "@/lib/periodParams";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Cash flow statement" };

/**
 * Module 10: the cash flow statement for a period, indirect or direct method, reconciled to the cash accounts, compared
 * with up to three other periods (previous period, the same period last year, any preset or dates of the reader's own).
 */
export default async function CashFlowPage({ searchParams }: PageProps<"/reports/cash-flow">) {
  const params = await searchParams;
  const period = await getReportPeriod(params);
  const direct = params.method === "direct";
  const cmp = pickRangeComparisons(params, period.picked, period.ctx);
  const diff = pickDiff(params, "none");
  const shown = compareQuery(cmp.slots, diff);
  const link = (method: string) => `/reports/cash-flow?${new URLSearchParams({ ...periodQuery(period.picked), ...shown, ...(method === "direct" ? { method } : {}) })}`;
  const ranges = [{ from: period.from, to: period.to }, ...cmp.periods];
  const results = direct
    ? await Promise.all(ranges.map((r) => getDirectCashFlow({ from: r.from, to: r.to })))
    : await Promise.all(ranges.map((r) => getCashFlow({ from: r.from, to: r.to })));
  const first = results[0];
  const all = results.every(Boolean);
  const ownLabel = period.picked.from && period.picked.to ? periodColumnLabel({ from: period.picked.from, to: period.picked.to }, period.ctx) : "This period";
  const headers = [cmp.periods.length ? ownLabel : "Amount", ...cmp.periods.map((p) => p.label)];
  const lines = !all ? [] : direct
    ? directCashFlowLines(results as NonNullable<Awaited<ReturnType<typeof getDirectCashFlow>>>[])
    : cashFlowLines(results as NonNullable<Awaited<ReturnType<typeof getCashFlow>>>[]);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash flow statement"
        actions={<ExportLink report="cash-flow" params={{ ...exportPeriod(period.picked), ...shown, ...(direct ? { method: "direct" } : {}) }} />} />
      <DateRangeFilter picked={period.picked} keep={params} fields={COMPARE_FIELDS}>
        <CompareFilter slots={cmp.slots} diff={diff} />
      </DateRangeFilter>
      <Segmented label="Method" size="sm" items={[
        { key: "indirect", href: link("indirect"), label: "Indirect (from the profit)", active: !direct },
        { key: "direct", href: link("direct"), label: "Direct (cash received and paid)", active: direct },
      ]} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/cash-flow" />
        {!first || !all ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label={direct ? "Cash flow statement, direct method" : "Cash flow statement"}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <ReportHeader title={direct ? "Cash flow statement (direct method)" : "Cash flow statement (indirect method)"} period={rangeLabel(period.picked, period.ctx)}
                filters={[cmp.periods.length ? `Compared with ${cmp.periods.map((p) => p.label).join(", ")}` : null]} />
              {first.balanced ? <Pill tone="good">Agrees with the cash accounts</Pill> : <Pill tone="bad">Does not agree with the cash accounts</Pill>}
            </div>
            <CompareTable lines={lines} headers={headers} diff={diff} />
            <p className="mt-3 text-[13px] text-muted">
              {direct
                ? <>Every entry that moved a cash, bank or mobile-money account is shown by its other side: sales and receivables are received from customers, expenses,
                  stock and payables are paid to suppliers, and so on. Money moved between the company&apos;s own cash accounts cancels out.</>
                : <>Cash is the cash, bank and mobile-money accounts (receipts not yet banked included). Investing is property, plant and equipment at cost; financing is
                  loans and capital; everything else on the balance sheet is working capital, with depreciation added back as a charge that used no cash.</>}
            </p>
            <ReportFooter basis={null} currency={first.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

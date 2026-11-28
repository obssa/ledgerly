import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle } from "@/components/ui";
import { getFinancialHealth, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { showRatio } from "@/lib/reports";
import { rangeLabel } from "@/lib/periods";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { CLOSED_RANGE_PRESETS } from "@/lib/periods";
import { ReportsNav } from "../ReportsNav";

export const metadata = { title: "Financial health" };

/** Module 10: the financial health (liquidity) pack, each measure with how it is worked out. */
export default async function FinancialHealthPage({ searchParams }: PageProps<"/reports/financial-health">) {
  const q = await searchParams;
  // Ratios over a number of days need a start and an end: no open-ended presets here.
  const period = await getReportPeriod(q, "this_fiscal_year_to_date", CLOSED_RANGE_PRESETS);
  const h = await getFinancialHealth(period);

  return (
    <>
      <PageTitle title="Financial health" />
      <DateRangeFilter picked={period.picked} keep={q} allowed={CLOSED_RANGE_PRESETS} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/financial-health" />
        {!h ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <div className="min-w-0 flex-[4_1_560px]">
            <ReportHeader title="Financial health" period={rangeLabel(period.picked, period.ctx)} filters={[`Balances at the end; margins, DSO and DPO over ${h.days} days`]} />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
              {[
                ["Current ratio", showRatio(h.ratios.current_ratio), `Current assets ${formatAmount(h.figures.current_assets)} ÷ current liabilities ${formatAmount(h.figures.current_liabilities)}`],
                ["Quick ratio", showRatio(h.ratios.quick_ratio), `(Current assets − inventory ${formatAmount(h.figures.inventory)}) ÷ current liabilities`],
                ["Cash runway", showRatio(h.ratios.cash_runway_months, " months"), h.figures.monthly_cash_outflow
                  ? `Cash ${formatAmount(h.figures.cash)} ÷ average monthly outflow ${formatAmount(h.figures.monthly_cash_outflow)} (last 3 months)`
                  : `Cash ${formatAmount(h.figures.cash)}; it did not fall over the last 3 months`],
                ["Days sales outstanding", showRatio(h.ratios.dso_days, " days"), `Receivables ${formatAmount(h.figures.receivables)} ÷ credit sales ${formatAmount(h.figures.credit_sales)} × ${h.days} days`],
                ["Days payable outstanding", showRatio(h.ratios.dpo_days, " days"), `Payables ${formatAmount(h.figures.payables)} ÷ purchases ${formatAmount(h.figures.purchases)} × ${h.days} days`],
                ["Gross margin", showRatio(h.ratios.gross_margin, "%"), `Gross profit ${formatAmount(h.figures.gross_profit)} ÷ revenue ${formatAmount(h.figures.revenue)}`],
                ["Net margin", showRatio(h.ratios.net_margin, "%"), `Net profit ${formatAmount(h.figures.net_profit)} ÷ revenue ${formatAmount(h.figures.revenue)}`],
              ].map(([label, value, how]) => (
                <Card key={label} label={label}>
                  <div className="text-[13px] text-muted">{label}</div>
                  <div className="num mt-1.5 text-[26px] font-semibold">{value}</div>
                  <p className="mt-2 text-[12px] text-muted">{how}</p>
                  <ReportFooter currency={h.currency} />
                </Card>
              ))}
            </div>
            <p className="mt-3 text-[13px] text-muted">A measure with nothing to divide by shows a dash. Current means everything but fixed assets and loans.</p>
          </div>
        )}
      </div>
    </>
  );
}

import { Card, PageTitle, Pill } from "@/components/ui";
import { getCashFlow, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { ExportLink, PeriodForm, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Cash flow statement" };

const TITLES = { operating: "Operating activities", investing: "Investing activities", financing: "Financing activities" } as const;

/** Module 10: the cash flow statement for a period, indirect method, reconciled to the cash accounts. */
export default async function CashFlowPage({ searchParams }: PageProps<"/reports/cash-flow">) {
  const period = await getReportPeriod(await searchParams);
  const cf = await getCashFlow(period);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash flow statement" actions={<ExportLink report="cash-flow" params={period} />} />
      <PeriodForm from={period.from} to={period.to} />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/cash-flow" />
        {!cf ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Cash flow statement">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="text-[13px] text-muted">{periodLabel(cf.from, cf.to)} · {cf.currency} · indirect method</div>
              {cf.balanced ? <Pill tone="good">Agrees with the cash accounts</Pill> : <Pill tone="bad">Does not agree with the cash accounts</Pill>}
            </div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <tbody>
                  {(Object.keys(TITLES) as (keyof typeof TITLES)[]).map((k) => (
                    <SectionRows key={k} title={TITLES[k]} total={cf.totals[k]} currency={cf.currency}
                      rows={k === "operating" ? [{ code: "", name: "Net profit for the period", amount: cf.net_profit }, ...cf.sections.operating] : cf.sections[k]} />
                  ))}
                  <tr className="bg-[#f0faf7] font-extrabold"><td>Net change in cash</td><td className="num text-right">{formatAmount(cf.net_change, cf.currency)}</td></tr>
                  <tr><td className="pl-7">Cash at the start</td><td className="num text-right">{formatAmount(cf.opening_cash, cf.currency)}</td></tr>
                  <tr className="bg-side font-extrabold text-white"><td>Cash at the end</td><td className="num text-right">{formatAmount(cf.closing_cash, cf.currency)}</td></tr>
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-[13px] text-muted">
              Cash is the cash, bank and mobile-money accounts (receipts not yet banked included). Investing is property, plant and equipment at cost; financing is
              loans and capital; everything else on the balance sheet is working capital, with depreciation added back as a charge that used no cash.
            </p>
          </Card>
        )}
      </div>
    </>
  );
}

function SectionRows({ title, rows, total, currency }: { title: string; rows: { code: string; name: string; amount: string }[]; total: string; currency: string }) {
  return (
    <>
      <tr><th scope="rowgroup" colSpan={2} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {rows.length === 0 && <tr><td className="pl-7 text-muted" colSpan={2}>None in the period.</td></tr>}
      {rows.map((r) => <tr key={`${r.code}-${r.name}`}><td className="pl-7">{r.name}</td><td className="num text-right">{formatAmount(r.amount, currency)}</td></tr>)}
      <tr className="font-bold"><td>Cash from {title.toLowerCase()}</td><td className="num text-right">{formatAmount(total, currency)}</td></tr>
    </>
  );
}

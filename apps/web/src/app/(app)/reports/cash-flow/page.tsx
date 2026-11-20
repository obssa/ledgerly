import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getCashFlow, getDirectCashFlow, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { ExportLink, PeriodForm, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Cash flow statement" };

const TITLES = { operating: "Operating activities", investing: "Investing activities", financing: "Financing activities" } as const;

/** Module 10: the cash flow statement for a period, indirect or direct method, reconciled to the cash accounts. */
export default async function CashFlowPage({ searchParams }: PageProps<"/reports/cash-flow">) {
  const params = await searchParams;
  const period = await getReportPeriod(params);
  const direct = params.method === "direct";
  const link = (method: string) => `/reports/cash-flow?${new URLSearchParams({ from: period.from, to: period.to, ...(method === "direct" ? { method } : {}) })}`;
  if (direct) return <DirectPage period={period} link={link} />;
  const cf = await getCashFlow(period);

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash flow statement" actions={<ExportLink report="cash-flow" params={period} />} />
      <PeriodForm from={period.from} to={period.to} />
      <MethodLinks link={link} direct={false} />
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

function MethodLinks({ link, direct }: { link: (method: string) => string; direct: boolean }) {
  return (
    <Segmented label="Method" size="sm" items={[
      { key: "indirect", href: link("indirect"), label: "Indirect (from the profit)", active: !direct },
      { key: "direct", href: link("direct"), label: "Direct (cash received and paid)", active: direct },
    ]} />
  );
}

/** The direct method: what the cash accounts received and paid, by what it was for. */
async function DirectPage({ period, link }: { period: { from: string; to: string }; link: (method: string) => string }) {
  const cf = await getDirectCashFlow(period);
  const sections = [["Operating activities", ["customers", "suppliers", "employees", "taxes", "other"], "operating"], ["Investing activities", ["investing"], "investing"],
    ["Financing activities", ["financing"], "financing"]] as const;
  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Cash flow statement" />
      <PeriodForm from={period.from} to={period.to} />
      <MethodLinks link={link} direct />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/cash-flow" />
        {!cf ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Cash flow statement, direct method">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="text-[13px] text-muted">{periodLabel(cf.from, cf.to)} · {cf.currency} · direct method</div>
              {cf.balanced ? <Pill tone="good">Agrees with the cash accounts</Pill> : <Pill tone="bad">Does not agree with the cash accounts</Pill>}
            </div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <tbody>
                  {sections.map(([title, keys, total]) => (
                    <SectionRows key={title} title={title} total={cf.totals[total]} currency={cf.currency}
                      rows={cf.lines.filter((l) => (keys as readonly string[]).includes(l.key) && (l.accounts.length > 0 || keys.length > 1)).map((l) => ({ code: l.key, name: l.label, amount: l.amount }))} />
                  ))}
                  <tr className="bg-[#f0faf7] font-extrabold"><td>Net change in cash</td><td className="num text-right">{formatAmount(cf.net_change, cf.currency)}</td></tr>
                  <tr><td className="pl-7">Cash at the start</td><td className="num text-right">{formatAmount(cf.opening_cash, cf.currency)}</td></tr>
                  <tr className="bg-side font-extrabold text-white"><td>Cash at the end</td><td className="num text-right">{formatAmount(cf.closing_cash, cf.currency)}</td></tr>
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-[13px] text-muted">
              Every entry that moved a cash, bank or mobile-money account is shown by its other side: sales and receivables are received from customers, expenses,
              stock and payables are paid to suppliers, and so on. Money moved between the company&apos;s own cash accounts cancels out.
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

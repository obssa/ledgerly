import { Card, PageTitle, Pill } from "@/components/ui";
import { getBalanceSheet, getCompanySettings, getCompanyToday } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { isDate } from "@/lib/reports";
import { PeriodForm, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Balance sheet" };

/** Module 10: the balance sheet at a date, with Assets = Liabilities + Equity checked and any difference shown. */
export default async function BalanceSheetPage({ searchParams }: PageProps<"/reports/balance-sheet">) {
  const q = await searchParams;
  const asOf = isDate(q.as_of) ? q.as_of : await getCompanyToday();
  const [bs, settings] = await Promise.all([getBalanceSheet(asOf), getCompanySettings()]);
  const cur = settings.base_currency;
  const section = (title: string, rows: { code: string; name: string; amount: string }[], total: string) => (
    <>
      <tr><th scope="rowgroup" colSpan={2} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {rows.map((r) => <tr key={r.code}><td className="pl-7"><span className="num text-muted">{r.code}</span> {r.name}</td><td className="num text-right">{formatAmount(r.amount, cur)}</td></tr>)}
      <tr className="font-bold"><td>Total {title.toLowerCase()}</td><td className="num text-right">{formatAmount(total, cur)}</td></tr>
    </>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Balance sheet" />
      <PeriodForm to={asOf} single />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/balance-sheet" />
        {!bs ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Balance sheet">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="text-[13px] text-muted">As at <span className="num">{asOf}</span> · {cur}</div>
              {bs.equation.balanced ? <Pill tone="good">Assets = Liabilities + Equity</Pill> : <Pill tone="bad">Out by {formatAmount(bs.equation.difference, cur)}</Pill>}
            </div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <tbody>
                  {section("Assets", bs.sections.assets, bs.equation.assets)}
                  {section("Liabilities", bs.sections.liabilities, bs.equation.liabilities)}
                  {section("Equity", bs.sections.equity, bs.equation.equity)}
                  <tr><td className="pl-7">Profit not yet closed to retained earnings</td><td className="num text-right">{formatAmount(bs.equation.profit_to_date, cur)}</td></tr>
                  <tr className="bg-side font-extrabold text-white"><td>Liabilities and equity</td><td className="num text-right">{formatAmount(bs.equation.liabilities_and_equity, cur)}</td></tr>
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </div>
    </>
  );
}

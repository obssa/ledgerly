import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getBalanceSheet, getCompanySettings, getCompanyToday } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { isDate, yearEarlier } from "@/lib/reports";
import { ExportLink, PeriodForm, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Balance sheet" };

/** Module 10: the balance sheet at a date, with Assets = Liabilities + Equity checked and any difference shown. */
export default async function BalanceSheetPage({ searchParams }: PageProps<"/reports/balance-sheet">) {
  const q = await searchParams;
  const asOf = isDate(q.as_of) ? q.as_of : await getCompanyToday();
  // Comparison with last year (compare=year): the balance sheet a year earlier, account by account.
  const lastYear = q.compare === "year";
  const before = yearEarlier(asOf);
  const [bs, prior, settings] = await Promise.all([getBalanceSheet(asOf), lastYear ? getBalanceSheet(before) : Promise.resolve(null), getCompanySettings()]);
  const cur = settings.base_currency;
  const cols = prior ? 3 : 2;
  type Key = "assets" | "liabilities" | "equity";
  // Every account in either year, in code order: one that was empty in a year shows nothing there.
  const merged = (key: Key) => {
    const now = new Map((bs?.sections[key] ?? []).map((r) => [r.code, r]));
    const then = new Map((prior?.sections[key] ?? []).map((r) => [r.code, r]));
    return [...new Set([...now.keys(), ...then.keys()])].sort().map((code) => ({
      code, name: (now.get(code) ?? then.get(code))!.name, amount: now.get(code)?.amount ?? null, previous: then.get(code)?.amount ?? null,
    }));
  };
  const amount = (v: string | null) => (v === null ? "—" : formatAmount(v, cur));
  const section = (title: string, key: Key, total: string, previous: string | null) => (
    <>
      <tr><th scope="rowgroup" colSpan={cols} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {merged(key).filter((r) => prior || r.amount !== null).map((r) => (
        <tr key={r.code}><td className="pl-7"><span className="num text-muted">{r.code}</span> {r.name}</td>
          <td className="num text-right">{amount(r.amount)}</td>{prior && <td className="num text-right">{amount(r.previous)}</td>}</tr>
      ))}
      <tr className="font-bold"><td>Total {title.toLowerCase()}</td><td className="num text-right">{formatAmount(total, cur)}</td>{prior && <td className="num text-right">{amount(previous)}</td>}</tr>
    </>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Balance sheet" actions={<ExportLink report="balance-sheet" params={{ as_of: asOf }} />} />
      <PeriodForm to={asOf} single />
      <Segmented label="Compare with" size="sm" items={[
        { key: "one", href: `/reports/balance-sheet?as_of=${asOf}`, label: "This date only", active: !lastYear },
        { key: "year", href: `/reports/balance-sheet?as_of=${asOf}&compare=year`, label: `Against ${before}`, active: lastYear },
      ]} />
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
                {prior && (
                  <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">{asOf}</th><th scope="col" className="!text-right">{before}</th></tr></thead>
                )}
                <tbody>
                  {section("Assets", "assets", bs.equation.assets, prior?.equation.assets ?? null)}
                  {section("Liabilities", "liabilities", bs.equation.liabilities, prior?.equation.liabilities ?? null)}
                  {section("Equity", "equity", bs.equation.equity, prior?.equation.equity ?? null)}
                  <tr><td className="pl-7">Profit not yet closed to retained earnings</td><td className="num text-right">{formatAmount(bs.equation.profit_to_date, cur)}</td>
                    {prior && <td className="num text-right">{formatAmount(prior.equation.profit_to_date, cur)}</td>}</tr>
                  <tr className="bg-side font-extrabold text-white"><td>Liabilities and equity</td><td className="num text-right">{formatAmount(bs.equation.liabilities_and_equity, cur)}</td>
                    {prior && <td className="num text-right">{formatAmount(prior.equation.liabilities_and_equity, cur)}</td>}</tr>
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </div>
    </>
  );
}

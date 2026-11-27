import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { asOfLabel, shiftDay } from "@/lib/periods";
import { percentChange } from "@/lib/decimal";
import { getBalanceSheet, getCompanySettings, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Balance sheet" };

/** Module 10: the balance sheet at a date, with Assets = Liabilities + Equity checked and any difference shown. */
export default async function BalanceSheetPage({ searchParams }: PageProps<"/reports/balance-sheet">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  // Compared with the same date a month, a quarter or a year earlier (QuickBooks' previous period and previous year),
  // months in the company's calendar; % change beside it.
  const COMPARE = { month: 1, quarter: 3, year: 12 } as const;
  const compare = typeof q.compare === "string" && q.compare in COMPARE ? (q.compare as keyof typeof COMPARE) : null;
  const before = compare ? shiftDay(asOf, -COMPARE[compare], at.ctx.calendar) : null;
  const pct = Boolean(compare) && q.pct === "1";
  const [bs, prior, settings] = await Promise.all([getBalanceSheet(asOf), before ? getBalanceSheet(before) : Promise.resolve(null), getCompanySettings()]);
  const cur = settings.base_currency;
  const cols = prior ? (pct ? 4 : 3) : 2;
  const here = (o: Record<string, string | undefined>) =>
    `/reports/balance-sheet?${new URLSearchParams(Object.entries({ as_of: asOf, compare: compare ?? undefined, pct: pct ? "1" : undefined, ...o }).filter(([, v]) => v) as [string, string][])}`;
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
          <td className="num text-right">{amount(r.amount)}</td>{prior && <td className="num text-right">{amount(r.previous)}</td>}
          {pct && <td className="num text-right text-muted">{percentChange(r.amount ?? "0.00", r.previous)}</td>}</tr>
      ))}
      <tr className="font-bold"><td>Total {title.toLowerCase()}</td><td className="num text-right">{formatAmount(total, cur)}</td>{prior && <td className="num text-right">{amount(previous)}</td>}
        {pct && <td className="num text-right">{percentChange(total, previous)}</td>}</tr>
    </>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Balance sheet" actions={<ExportLink report="balance-sheet" params={{ as_of: asOf, compare: compare ?? undefined, pct: pct ? "1" : undefined }} />} />
      <AsOfFilter picked={at.picked} keep={q} />
      <div className="mb-4 flex flex-wrap items-center gap-x-3">
        <Segmented label="Compare with" size="sm" className="!mb-2" items={[
          { key: "one", href: here({ compare: undefined, pct: undefined }), label: "This date only", active: !compare },
          { key: "month", href: here({ compare: "month" }), label: "A month earlier", active: compare === "month" },
          { key: "quarter", href: here({ compare: "quarter" }), label: "A quarter earlier", active: compare === "quarter" },
          { key: "year", href: here({ compare: "year" }), label: "A year earlier", active: compare === "year" },
        ]} />
        {compare && <a href={here({ pct: pct ? undefined : "1" })} className="mb-2 text-[13px] font-semibold text-brand hover:underline">{pct ? "Hide % change" : "Show % change"}</a>}
      </div>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/balance-sheet" />
        {!bs ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Balance sheet">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <ReportHeader title="Balance sheet" period={asOfLabel(asOf, at.ctx)} filters={[before ? `Compared with ${asOfLabel(before, at.ctx).replace(/^As of /, "")}` : null]} />
              {bs.equation.balanced ? <Pill tone="good">Assets = Liabilities + Equity</Pill> : <Pill tone="bad">Out by {formatAmount(bs.equation.difference, cur)}</Pill>}
            </div>
            <div className="overflow-x-auto">
              <table className="tbl">
                {prior && (
                  <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">{asOf}</th><th scope="col" className="!text-right">{before}</th>{pct && <th scope="col" className="!text-right">% change</th>}</tr></thead>
                )}
                <tbody>
                  {section("Assets", "assets", bs.equation.assets, prior?.equation.assets ?? null)}
                  {section("Liabilities", "liabilities", bs.equation.liabilities, prior?.equation.liabilities ?? null)}
                  {section("Equity", "equity", bs.equation.equity, prior?.equation.equity ?? null)}
                  <tr><td className="pl-7">Profit not yet closed to retained earnings</td><td className="num text-right">{formatAmount(bs.equation.profit_to_date, cur)}</td>
                    {prior && <td className="num text-right">{formatAmount(prior.equation.profit_to_date, cur)}</td>}
                    {pct && <td className="num text-right text-muted">{percentChange(bs.equation.profit_to_date, prior?.equation.profit_to_date ?? null)}</td>}</tr>
                  <tr className="bg-side font-extrabold text-white"><td>Liabilities and equity</td><td className="num text-right">{formatAmount(bs.equation.liabilities_and_equity, cur)}</td>
                    {prior && <td className="num text-right">{formatAmount(prior.equation.liabilities_and_equity, cur)}</td>}
                    {pct && <td className="num text-right">{percentChange(bs.equation.liabilities_and_equity, prior?.equation.liabilities_and_equity ?? null)}</td>}</tr>
                </tbody>
              </table>
            </div>
            <ReportFooter currency={cur} />
          </Card>
        )}
      </div>
    </>
  );
}

import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { Card, PageTitle, Pill } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { asOfLabel } from "@/lib/periods";
import { COMPARE_FIELDS, compareQuery, pickAsOfComparisons, pickDiff } from "@/lib/compare";
import { balanceSheetLines } from "@/lib/compareLines";
import { formatDate } from "@/lib/dates";
import { getBalanceSheet, getCompanySettings, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Balance sheet" };

/**
 * Module 10: the balance sheet at a date, with Assets = Liabilities + Equity checked and any difference shown, compared
 * with up to three other dates (a month, a quarter, a year or two years earlier in the company's calendar, any preset
 * or a date of the reader's own), with the change and % change against the first.
 */
export default async function BalanceSheetPage({ searchParams }: PageProps<"/reports/balance-sheet">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const cmp = pickAsOfComparisons(q, asOf, at.ctx);
  // Older links: pct=1 asked for % change.
  const diff = pickDiff(q, q.pct === "1" ? "pct" : "none");
  const [results, settings] = await Promise.all([Promise.all([asOf, ...cmp.dates.map((d) => d.asOf)].map((d) => getBalanceSheet(d))), getCompanySettings()]);
  const bs = results[0];
  const cur = settings.base_currency;
  const shown = results.every(Boolean) ? (results as NonNullable<(typeof results)[number]>[]) : null;
  const headers = [formatDate(asOf, { calendar: at.ctx.calendar, dateFormat: at.ctx.dateFormat ?? "DD/MM/YYYY" }), ...cmp.dates.map((d) => d.label)];
  // Show foreign currency (QuickBooks' option): beside an account kept in another currency, its balance in that currency.
  const fx = q.fx === "1";
  const foreign = new Map((bs ? [...bs.sections.assets, ...bs.sections.liabilities, ...bs.sections.equity] : []).filter((r) => r.currency).map((r) => [r.code, r]));
  const params = { as_of: asOf, ...compareQuery(cmp.slots, diff), ...(fx ? { fx: "1" } : {}) };

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Balance sheet" actions={<ExportLink report="balance-sheet" params={params} />} />
      <AsOfFilter picked={at.picked} keep={q} fields={["compare", "pct", "fx", ...COMPARE_FIELDS]}>
        <label className="flex h-[42px] items-center gap-2 text-sm"><input type="checkbox" name="fx" value="1" defaultChecked={fx} className="size-4" /> Show foreign currency</label>
        <CompareFilter slots={cmp.slots} diff={diff} asOf />
      </AsOfFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/balance-sheet" />
        {!bs || !shown ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
          <Card className="min-w-0 flex-[4_1_560px]" label="Balance sheet">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <ReportHeader title="Balance sheet" period={asOfLabel(asOf, at.ctx)}
                filters={[cmp.dates.length ? `Compared with ${cmp.dates.map((d) => asOfLabel(d.asOf, at.ctx).replace(/^As of /, "")).join(", ")}` : null]} />
              {bs.equation.balanced ? <Pill tone="good">Assets = Liabilities + Equity</Pill> : <Pill tone="bad">Out by {formatAmount(bs.equation.difference, cur)}</Pill>}
            </div>
            <CompareTable lines={balanceSheetLines(shown)} headers={headers} diff={diff} codes
              extra={fx ? { header: "Foreign balance", value: (l) => { const f = l.code ? foreign.get(l.code) : undefined; return f ? `${f.currency} ${formatAmount(f.foreign_balance ?? "0.00")}` : ""; } } : undefined} />
            <ReportFooter currency={cur} />
          </Card>
        )}
      </div>
    </>
  );
}

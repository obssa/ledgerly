import { parseDate, type DatePrefs } from "@/lib/dates";
import { DateInput } from "@/components/DateInput";
import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getTaxDepreciation, getDatePrefs } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Tax depreciation" };

const RULE: Record<string, string> = { straight_line: "Straight line", reducing_balance: "Reducing balance" };
/** A date from the form: as stored, or typed the company's way (it works without JavaScript). */
const yearEnd = (v: unknown, prefs: DatePrefs) => (typeof v === "string" ? parseDate(v, prefs) ?? undefined : undefined);

/**
 * Module 9c: a fiscal year's tax depreciation beside the books (061). Each asset's written-down value for tax, the
 * depreciation the tax rule allows in the year, and the book value, with the difference the tax return and deferred
 * tax work from. A schedule only: nothing posts.
 */
export default async function TaxDepreciationPage({ searchParams }: PageProps<"/fixed-assets/tax">) {
  const q = await searchParams;
  const year = await getTaxDepreciation(yearEnd(q.year_end, await getDatePrefs()));

  return (
    <>
      <PageTitle title="Tax depreciation" />
      {year === null ? (
        <p className="text-sm text-muted">Not available in the demo.</p>
      ) : (
        <>
          <form className="mb-4 flex flex-wrap items-end gap-2.5" action="/fixed-assets/tax">
            <div><label className="field-label" htmlFor="year_end">Year ending</label><DateInput plain className="field" id="year_end" name="year_end" defaultValue={year.to} /></div>
            <button type="submit" className="btn">Show</button>
            <p className="text-[13px] text-muted">From <span className="num"><Dt v={year.from} /></span> to <span className="num"><Dt v={year.to} /></span>. Each asset by its tax rule, or its book rule when it has none.</p>
          </form>
          <Card className="!p-2" label="Tax depreciation schedule">
            {year.rows.length === 0 ? <p className="p-3 text-sm text-muted">No assets in this year.</p> : (
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead>
                    <tr>
                      <th scope="col">Asset</th><th scope="col">Tax rule</th>
                      <th scope="col" className="!text-right">Tax value at start</th><th scope="col" className="!text-right">Bought in the year</th>
                      <th scope="col" className="!text-right">Tax depreciation</th><th scope="col" className="!text-right">Tax value at end</th>
                      <th scope="col" className="!text-right">Book depreciation</th><th scope="col" className="!text-right">Book value at end</th>
                      <th scope="col" className="!text-right">Difference</th>
                    </tr>
                  </thead>
                  <tbody>
                    {year.rows.map((r) => (
                      <tr key={r.id}>
                        <td>
                          <Link href={`/fixed-assets/${encodeURIComponent(r.id)}`} className="font-semibold hover:underline"><span className="num">{r.code}</span> {r.name}</Link>
                          {r.disposed_on && <span className="block text-[12px] text-muted">Disposed of on <span className="num"><Dt v={r.disposed_on} /></span></span>}
                        </td>
                        <td className="text-[13px]">
                          {RULE[r.tax_rule.method] ?? r.tax_rule.method}
                          {r.tax_rule.method === "straight_line" ? `, ${r.tax_rule.useful_life_months} months` : `, ${Number(r.tax_rule.annual_rate) * 100}%`}
                          {!r.tax_rule.own && <span className="block text-[12px] text-muted">as in the books</span>}
                        </td>
                        <td className="num text-right">{formatAmount(r.tax_opening)}</td>
                        <td className="num text-right">{formatAmount(r.tax_additions)}</td>
                        <td className="num text-right">{formatAmount(r.tax_depreciation)}</td>
                        <td className="num text-right font-semibold">{formatAmount(r.tax_closing)}</td>
                        <td className="num text-right">{formatAmount(r.book_depreciation)}</td>
                        <td className="num text-right">{formatAmount(r.book_closing)}</td>
                        <td className="num text-right">{formatAmount(r.difference)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="font-bold">
                      <td colSpan={2}>Total</td>
                      <td className="num text-right">{formatAmount(year.totals.tax_opening)}</td>
                      <td className="num text-right">{formatAmount(year.totals.tax_additions)}</td>
                      <td className="num text-right">{formatAmount(year.totals.tax_depreciation)}</td>
                      <td className="num text-right">{formatAmount(year.totals.tax_closing)}</td>
                      <td className="num text-right">{formatAmount(year.totals.book_depreciation)}</td>
                      <td className="num text-right">{formatAmount(year.totals.book_closing)}</td>
                      <td className="num text-right">{formatAmount(year.totals.difference)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
          </Card>
          <p className="mt-3 max-w-[760px] text-[13px] text-muted">
            The difference is the tax value less the book value at the year end. Tax rules come from your tax adviser; an asset without one follows its book rule here.
          </p>
        </>
      )}
    </>
  );
}

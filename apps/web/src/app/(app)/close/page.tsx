import { parseDate, type DatePrefs } from "@/lib/dates";
import { DateInput } from "@/components/DateInput";
import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCloseChecklist, getYearEnd, getDatePrefs } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { CloseForm, CloseYear, ReopenYear } from "./CloseForm";

export const metadata = { title: "Close the books" };

/** A date from the form: as stored, or typed the company's way (it works without JavaScript). */
const date = (v: unknown, prefs: DatePrefs) => (typeof v === "string" ? parseDate(v, prefs) ?? undefined : undefined);

/** Module 6: the close checklist for a period end, and closing the books through it. */
export default async function ClosePage({ searchParams }: PageProps<"/close">) {
  const asked = date((await searchParams).period_end, await getDatePrefs());
  const [checklist, yearEnd] = await Promise.all([getCloseChecklist(asked), getYearEnd()]);

  return (
    <>
      <PageTitle title="Close the books" />
      {!checklist ? <p className="text-[14px] text-muted">Closing the books is for accountants, owners and admins (and not available in the demo).</p> : (
        <>
          <form className="mb-4 flex flex-wrap items-end gap-2.5">
            <div><label className="field-label" htmlFor="period_end">Period ends on</label><DateInput plain className="field" id="period_end" name="period_end" defaultValue={checklist.period_end} /></div>
            <button type="submit" className="btn">Check</button>
            <p className="text-[13px] text-muted">
              {checklist.books_closed_until ? <>The books are closed through <span className="num font-semibold text-ink">{checklist.books_closed_until}</span>.</> : "No period is closed yet."}
            </p>
          </form>
          <Card className="mb-4" label="Checklist">
            <h2 className="mb-3 text-[15px] font-bold">Before closing through <span className="num">{checklist.period_end}</span></h2>
            <ul className="grid gap-3">
              {checklist.items.map((i) => (
                <li key={i.key} className="border-t border-line pt-3 first:border-0 first:pt-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Pill tone={i.status === "ok" ? "good" : "warn"}>{i.status === "ok" ? "Done" : "To do"}</Pill>
                    <span className="font-semibold">{i.label}</span>
                    {i.link && i.status !== "ok" && <Link href={i.link} className="text-[13px] font-semibold text-brand hover:underline">Open</Link>}
                  </div>
                  <p className="mt-1 text-[13px] text-muted">{i.detail}</p>
                  {i.items.length > 0 && (
                    <ul className="mt-1.5 grid gap-1 text-[13px]">
                      {i.items.map((x, n) => <li key={`${i.key}-${n}`}><Link href={x.link} className="hover:underline">{x.label}</Link> <span className="text-muted">— {x.note}</span></li>)}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          </Card>
          {(!checklist.books_closed_until || checklist.books_closed_until < checklist.period_end) && (
            <Card className="max-w-[900px]" label="Close"><CloseForm periodEnd={checklist.period_end} warnings={checklist.warnings} /></Card>
          )}
          {yearEnd && (
            <Card className="mt-4" label="Year-end">
              <h2 className="mb-2 text-[15px] font-bold">Year-end</h2>
              <ul className="grid gap-1.5 text-[14px]">
                {yearEnd.years.map((y) => (
                  <li key={y.id} className="flex flex-wrap items-center gap-2">
                    <span className="num"><Dt v={y.starts_on} /> to <Dt v={y.ends_on} /></span>
                    <Pill tone={y.status === "closed" ? "good" : y.status === "reopened" ? "warn" : "neutral"}>{y.status === "closed" ? "Closed" : y.status === "reopened" ? "Reopened" : "Open"}</Pill>
                    {y.closed_by && y.status === "closed" && <span className="text-[13px] text-muted">by {y.closed_by}</span>}
                    {y.adjustments_closed_at && <Pill tone="good">Audit signed off</Pill>}
                    {y.status === "closed" && (
                      <Link href={`/close/years/${encodeURIComponent(y.id)}`} className="text-[13px] font-semibold text-brand hover:underline">
                        {y.adjustments_closed_at ? "Adjusted trial balance" : `Audit adjustments (${y.adjustments})`}
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
              {yearEnd.closing && (
                <div className="mt-4 border-t border-line pt-4">
                  <h3 className="mb-2 text-[14px] font-bold">Close the year to <span className="num"><Dt v={yearEnd.closing.year.ends_on} /></span></h3>
                  <p className="mb-2 text-[14px]">{yearEnd.closing.net_profit.startsWith("-") ? "Loss" : "Profit"} of the year: <span className="num font-semibold">{formatAmount(yearEnd.closing.net_profit.replace("-", ""))}</span>, from {yearEnd.closing.lines.length} income and expense account{yearEnd.closing.lines.length === 1 ? "" : "s"}.</p>
                  <CloseYear id={yearEnd.closing.year.id} endsOn={yearEnd.closing.year.ends_on} netProfit={yearEnd.closing.net_profit} />
                </div>
              )}
              {yearEnd.years.filter((y) => y.can_reopen).map((y) => (
                <div key={y.id} className="mt-4 border-t border-line pt-4">
                  <h3 className="mb-2 text-[14px] font-bold">Reopen the year to <span className="num"><Dt v={y.ends_on} /></span></h3>
                  <ReopenYear id={y.id} />
                </div>
              ))}
            </Card>
          )}
          <p className="mt-3 text-[13px] text-muted">Reopening a closed period is done under <Link href="/settings" className="font-semibold text-brand hover:underline">Company settings</Link>, by an owner or admin, with a reason.</p>
        </>
      )}
    </>
  );
}

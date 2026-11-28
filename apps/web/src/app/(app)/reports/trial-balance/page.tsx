import { Fragment } from "react";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { CompareFilter } from "@/components/CompareFilter";
import { asOfLabel, resolveRange } from "@/lib/periods";
import { COMPARE_FIELDS, compareQuery, pickAsOfComparisons } from "@/lib/compare";
import { trialBalanceLines } from "@/lib/compareLines";
import { formatDate } from "@/lib/dates";
import { getTrialBalance, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink } from "../ReportsNav";
import { trialTotals } from "@/lib/ledger";

export const metadata = { title: "Trial balance" };

/**
 * Module 6: every account's balance at a date, debits against credits; each account opens its general ledger. Compared
 * with up to three other dates, a debit and credit column for each, as QuickBooks shows a trial balance compared.
 */
export default async function TrialBalancePage({ searchParams }: PageProps<"/reports/trial-balance">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const cmp = pickAsOfComparisons(q, asOf, at.ctx);
  const dates = [asOf, ...cmp.dates.map((d) => d.asOf)];
  const results = await Promise.all(dates.map((d) => getTrialBalance(d)));
  const rows = results[0];
  const all = results.every(Boolean) ? (results as NonNullable<(typeof results)[number]>[]) : null;
  const totals = (all ?? []).map((r) => trialTotals(r));
  const lines = all ? trialBalanceLines(all) : [];
  // Show foreign currency (QuickBooks' option): an account kept in another currency, with that currency and its balance in it at the date.
  const fx = q.fx === "1";
  const foreign = new Map((rows ?? []).filter((r) => r.currency).map((r) => [r.code, r]));
  const prefs = { calendar: at.ctx.calendar, dateFormat: at.ctx.dateFormat ?? "DD/MM/YYYY" } as const;
  const compared = dates.length > 1;
  // Each account opens its ledger for the fiscal year up to this date.
  const from = resolveRange("this_fiscal_year", { ...at.ctx, today: asOf })?.from ?? asOf;
  const money = (v: string | null) => (v === null || v === "0.00" ? "" : formatAmount(v));

  return (
    <>
      <PageTitle
        title={<>Trial balance {rows && (totals[0]?.balanced ? <Pill tone="good">Balanced</Pill> : <Pill tone="bad">Out of balance</Pill>)}</>}
        actions={<ExportLink report="trial-balance" params={{ as_of: asOf, ...compareQuery(cmp.slots, null), ...(fx ? { fx: "1" } : {}) }} />} />
      <AsOfFilter picked={at.picked} keep={q} fields={["compare", "fx", ...COMPARE_FIELDS]}>
        <label className="flex h-[42px] items-center gap-2 text-sm"><input type="checkbox" name="fx" value="1" defaultChecked={fx} className="size-4" /> Show foreign currency</label>
        <CompareFilter slots={cmp.slots} diff="none" asOf />
      </AsOfFilter>
      {!rows || !all ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card className="!p-2" label="Trial balance">
          <div className="px-3 pt-3"><ReportHeader title="Trial balance" period={asOfLabel(asOf, at.ctx)}
            filters={[compared ? `Compared with ${cmp.dates.map((d) => d.label).join(", ")}` : null]} /></div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                {compared && <tr><th colSpan={fx ? 4 : 2} />{dates.map((d) => <th key={d} colSpan={2} scope="colgroup" className="!text-center">{formatDate(d, prefs)}</th>)}</tr>}
                <tr><th scope="col">Code</th><th scope="col">Account</th>{fx && <><th scope="col">Currency</th><th scope="col" className="!text-right">Foreign balance</th></>}{dates.map((d) => <Fragment key={d}><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></Fragment>)}</tr>
              </thead>
              <tbody>
                {lines.length === 0 && <tr><td colSpan={(fx ? 4 : 2) + 2 * dates.length} className="text-muted">Nothing posted yet.</td></tr>}
                {lines.map((r) => (
                  <tr key={r.code}>
                    <td className="num">{r.code}</td>
                    <td><Link href={`/reports/general-ledger?account_id=${encodeURIComponent(r.account_id)}&from=${from}&to=${asOf}`} className="hover:underline">{r.name}</Link></td>
                    {fx && <><td className="text-[13px]">{foreign.get(r.code)?.currency ?? ""}</td><td className="num text-right">{foreign.has(r.code) ? formatAmount(foreign.get(r.code)!.foreign_balance ?? "0.00") : ""}</td></>}
                    {r.values.map((v, i) => <td key={i} className="num text-right">{money(v)}</td>)}
                  </tr>
                ))}
              </tbody>
              <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={fx ? 4 : 2}>Total</td>
                {totals.map((t, i) => <Fragment key={i}><td className="num text-right">{formatAmount(t.debit)}</td><td className="num text-right">{formatAmount(t.credit)}</td></Fragment>)}</tr></tfoot>
            </table>
          </div>
          <ReportFooter />
        </Card>
      )}
    </>
  );
}

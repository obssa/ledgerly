import { asOfLabel } from "@/lib/periods";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { getApAgeing, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink } from "../ReportsNav";
import { AgeingDetail, AgeingViews } from "../AgeingDetail";

export const metadata = { title: "AP ageing" };

const COLUMNS = [
  ["current", "Not yet due"], ["days_1_30", "1–30 days"], ["days_31_60", "31–60"], ["days_61_90", "61–90"], ["over_90", "Over 90"],
  ["credits", "Advances"], ["total", "We owe"],
] as const;

/** What the company owes suppliers by how late it is. Each supplier's total is their balance on accounts payable. */
export default async function ApAgeingPage({ searchParams }: PageProps<"/reports/ap-ageing">) {
  const q = await searchParams;
  const detail = q.view === "detail";
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const ageing = await getApAgeing(asOf);

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Payables</>} title={detail ? "AP ageing detail" : "AP ageing summary"} actions={<ExportLink report="ap-ageing" params={{ as_of: asOf }} />} />
      {ageing === null ? (
        <Card><p className="text-sm text-muted">Not available in the demo.</p></Card>
      ) : (
        <>
          <AgeingViews base="/reports/ap-ageing" asOf={asOf} detail={detail} />
          <AsOfFilter picked={at.picked} keep={q} label="Days late counted at" />
          <ReportHeader title={`Accounts payable ageing ${detail ? "detail" : "summary"}`} period={asOfLabel(asOf, at.ctx.calendar)} />
          {detail ? <AgeingDetail ageing={ageing} kind="bill" baseCurrency={ageing.currency ?? ""} /> : (
          <Card className="!p-2" label="AP ageing">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr>
                    <th scope="col">Supplier</th>
                    {COLUMNS.map(([k, label]) => <th key={k} scope="col" className="!text-right">{label}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {ageing.rows.length === 0 && <tr><td colSpan={COLUMNS.length + 1} className="text-muted">Nothing is owed to suppliers.</td></tr>}
                  {ageing.rows.map((r) => (
                    <tr key={r.contact_id}>
                      <td className="font-semibold"><Link href={`/suppliers/${encodeURIComponent(r.contact_id)}/statement`} className="hover:underline">{r.name}</Link></td>
                      {COLUMNS.map(([k]) => (
                        <td key={k} className={`num text-right ${k === "total" ? "font-semibold" : ""} ${k === "over_90" && r.over_90 !== "0.00" ? "text-bad" : ""}`}>
                          {r[k] === "0.00" ? "—" : formatAmount(r[k])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
                {ageing.rows.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-ink font-bold">
                      <td>Total</td>
                      {COLUMNS.map(([k]) => <td key={k} className="num text-right">{formatAmount(ageing.totals[k])}</td>)}
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </Card>
          )}
          <ReportFooter basis={null} currency={ageing.currency ?? undefined} />
          <p className="mt-3 text-[13px] text-muted">Amounts in the company&apos;s currency, each bill at its own rate. The total equals accounts payable in the trial balance.</p>
        </>
      )}
    </>
  );
}

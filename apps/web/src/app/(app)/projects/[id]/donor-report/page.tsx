import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getDonorReport, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Donor report" };

/** Module 9a (093): what a grant was spent on against its budget, what it brought in, and the funds left, for its donor. */
export default async function DonorReportPage({ params, searchParams }: PageProps<"/projects/[id]/donor-report">) {
  const { id } = await params;
  const q = await searchParams;
  const period = await getReportPeriod(q, "all");
  const r = await getDonorReport(id, { from: (period.picked.from ?? undefined), to: (period.picked.to ?? undefined) });
  if (!r) notFound();
  const back = `/projects/${encodeURIComponent(r.project.id)}`;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/projects" className="hover:underline">Projects</Link> / <Link href={back} className="num hover:underline">{r.project.code}</Link></>}
        title="Donor report" />
      <DateRangeFilter picked={period.picked} keep={q} fields={[]}>

      </DateRangeFilter>
      <Card className="mb-4" label="Grant">
        <div className="mb-3 text-center">
          <div className="text-[17px] font-extrabold">{r.project.name}</div>
          <div className="text-[13px] text-muted">
            {r.grant.donor ? `Grant from ${r.grant.donor.name}` : "Grant"}{r.grant.reference ? ` · ${r.grant.reference}` : ""} · {r.from ? `${r.from} to ${r.to}` : `to ${r.to}`}
          </div>
        </div>
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Award</dt><dd className="num text-[18px] font-bold">{formatAmount(r.grant.award)}</dd></div>
          <div><dt className="text-muted">Received to date</dt><dd className="num text-[18px] font-bold">{formatAmount(r.income.to_date)}</dd></div>
          <div><dt className="text-muted">Spent to date</dt><dd className="num text-[18px] font-bold">{formatAmount(r.spent.to_date)}</dd></div>
          <div><dt className="text-muted">Funds held</dt><dd className={`num text-[18px] font-bold ${Number(r.fund_balance) < 0 ? "text-bad" : ""}`}>{formatAmount(r.fund_balance)}</dd></div>
          <div><dt className="text-muted">Still to come</dt><dd className="num">{formatAmount(r.award_to_come)}</dd></div>
        </dl>
      </Card>
      <Card className="mb-4 !p-2" label="Budget against actual">
        <div className="overflow-x-auto">
          <table className="tbl">
            <caption className="px-3 pt-2 text-left text-[14px] font-bold">Budget against actual</caption>
            <thead><tr><th scope="col">Budget line</th><th scope="col" className="!text-right">Budget</th><th scope="col" className="!text-right">This period</th>
              <th scope="col" className="!text-right">To date</th><th scope="col" className="!text-right">Left</th><th scope="col" className="!text-right">Used</th></tr></thead>
            <tbody>
              {r.lines.map((l) => (
                <tr key={l.account_id}>
                  <td><span className="num">{l.code}</span> {l.name} {l.over && <Pill tone="bad">Over budget</Pill>}</td>
                  <td className="num text-right">{formatAmount(l.budget)}</td>
                  <td className="num text-right">{formatAmount(l.period)}</td>
                  <td className="num text-right">{formatAmount(l.to_date)}</td>
                  <td className={`num text-right ${Number(l.remaining) < 0 ? "text-bad" : ""}`}>{formatAmount(l.remaining)}</td>
                  <td className="num text-right">{Number(l.budget) > 0 ? `${Math.round((Number(l.to_date) / Number(l.budget)) * 100)}%` : "—"}</td>
                </tr>
              ))}
              <tr className="font-semibold">
                <td>Total</td>
                <td className="num text-right">{formatAmount(r.totals.budget)}</td>
                <td className="num text-right">{formatAmount(r.totals.period)}</td>
                <td className="num text-right">{formatAmount(r.totals.to_date)}</td>
                <td className="num text-right">{formatAmount(r.totals.remaining)}</td>
                <td />
              </tr>
            </tbody>
          </table>
        </div>
        {r.lines.length === 0 && <p className="px-3 pb-2 text-[13px] text-muted">No budget lines yet: add them on the project.</p>}
      </Card>
      {r.outside_budget.length > 0 && (
        <Card className="mb-4 !p-2" label="Outside the budget">
          <table className="tbl">
            <caption className="px-3 pt-2 text-left text-[14px] font-bold">Spent outside the budget</caption>
            <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">This period</th><th scope="col" className="!text-right">To date</th></tr></thead>
            <tbody>
              {r.outside_budget.map((l) => (
                <tr key={l.account_id}><td><span className="num">{l.code}</span> {l.name}</td><td className="num text-right">{formatAmount(l.period)}</td><td className="num text-right">{formatAmount(l.to_date)}</td></tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      <p className="text-[13px] text-muted">Read from the ledger: income and expenses tagged with the project. Funds held are what it brought in less what it spent, to date.</p>
    </>
  );
}

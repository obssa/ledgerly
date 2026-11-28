import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { AccountTabs } from "../AccountTabs";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getCompanySettings, getStatement } from "@/lib/data";
import { STATEMENT_TABS, categorisable, signed, statementTab } from "@/lib/statements";
import { ApplyRules, ImportStatement, ReviewLine } from "../../StatementForms";

export const metadata = { title: "Statement" };

const TONE = { imported: "warn", matched: "good", categorised: "good", excluded: "neutral", reconciled: "brand" } as const;
const LABEL = { imported: "To review", matched: "Matched", categorised: "Categorised", excluded: "Excluded", reconciled: "Reconciled" } as const;

/** Module 5: a money account's statement lines, each matched, categorised or excluded; and the import of new ones. */
export default async function StatementPage({ params, searchParams }: PageProps<"/banking/[id]/statement">) {
  const { id } = await params;
  const tab = statementTab((await searchParams).status);
  const [statement, company] = await Promise.all([getStatement(id, tab), getCompanySettings()]);
  if (!statement) notFound();
  const { lines, accounts } = statement;
  const options = categorisable(accounts, id);
  const href = (status: string) => `/banking/${encodeURIComponent(id)}/statement${status === "imported" ? "" : `?status=${status}`}`;
  const total = Object.values(lines.counts).reduce((a, b) => a + b, 0);

  return (
    <>
      <PageTitle
        crumbs={<><Link href={`/banking/${encodeURIComponent(id)}`} className="hover:underline">
          <span className="num">{lines.account.code}</span> {lines.account.name}</Link> › Statement</>}
        title="Statement"
      />
      <AccountTabs id={id} current="statement" />
      <Segmented label="Statement lines" items={STATEMENT_TABS.map((t) => ({
        key: t.status, href: href(t.status), label: t.label, active: tab === t.status,
        count: t.status === "all" ? total : lines.counts[t.status],
      }))} />
      {lines.can.review && tab === "imported" && lines.data.some((l) => l.rule) && (
        <div className="mb-3"><ApplyRules accountId={id} /></div>
      )}
      <Card className="!p-2" label="Lines">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">What the bank says</th><th scope="col" className="!text-right">Amount ({lines.account.currency})</th><th scope="col">Status</th><th scope="col" className="min-w-[320px]">Review</th></tr></thead>
            <tbody>
              {lines.data.length === 0 && (
                <tr><td colSpan={5} className="text-muted">{tab === "imported" ? (total === 0 ? "No statement imported yet." : "Every line is reviewed.") : "No lines here."}</td></tr>
              )}
              {lines.data.map((l) => {
                const amount = signed(l.amount);
                return (
                  <tr key={l.id} className="align-top">
                    <td className="num"><Dt v={l.date} /></td>
                    <td>
                      <div className="font-semibold">{l.description}</div>
                      {l.reference && <div className="num text-[13px] text-muted">{l.reference}</div>}
                    </td>
                    <td className={`num text-right font-semibold ${amount.direction === "in" ? "text-good" : ""}`}>
                      {amount.text}
                    </td>
                    <td><Pill tone={TONE[l.status]}>{LABEL[l.status]}</Pill></td>
                    <td><ReviewLine line={l} accounts={options} canReview={lines.can.review} foreign={lines.account.currency !== company.base_currency ? { currency: lines.account.currency, base: company.base_currency } : undefined} /></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
      {lines.can.review && (
        <Card className="mt-4 max-w-[900px]" label="Import">
          <h2 className="mb-2 text-[15px] font-bold">Import a statement</h2>
          <ImportStatement accountId={id} />
        </Card>
      )}
    </>
  );
}

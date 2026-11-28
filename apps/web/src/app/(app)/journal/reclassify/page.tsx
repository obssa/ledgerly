import { Dt } from "@/components/Dt";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { Card, PageTitle } from "@/components/ui";
import { getGeneralLedger, getJournalOptions, getReportPeriod } from "@/lib/data";
import { journalAccounts } from "@/lib/journal";
import { ReclassifyForm } from "../JournalForms";

export const metadata = { title: "Reclassify lines" };

const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

/** Module 6, accountant tools: lines posted to the wrong account, moved to the right one in bulk by one manual journal. */
export default async function ReclassifyPage({ searchParams }: PageProps<"/journal/reclassify">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "this_fiscal_year_to_date");
  const filters = { from: period.from, to: period.to, account_id: uuid(q.account_id) };
  const [gl, options] = await Promise.all([filters.account_id ? getGeneralLedger(filters) : Promise.resolve(null), getJournalOptions()]);
  const accounts = options ? journalAccounts(options.accounts, options.baseCurrency) : [];
  const account = gl?.accounts.find((a) => a.account_id === filters.account_id);

  return (
    <>
      <PageTitle crumbs={<>Reclassify</>} title="Reclassify lines" />
      {!options ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <>
          <DateRangeFilter picked={period.picked} keep={q} fields={["account_id"]}>
            <div className="min-w-[240px]">
              <label className="field-label" htmlFor="account_id">Lines of</label>
              <select className="field" id="account_id" name="account_id" defaultValue={filters.account_id ?? ""}>
                <option value="">Choose an account…</option>
                {accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
              </select>
            </div>
      </DateRangeFilter>
          {gl?.truncated && <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Only the first lines of a long period are shown: choose a shorter period.</p>}
          {filters.account_id && (
            <Card label="Lines to move">
              <h2 className="mb-3 text-[15px] font-bold">{account ? <><span className="num">{account.code}</span> {account.name}</> : "Lines"}, <Dt v={filters.from} /> to <Dt v={filters.to} /></h2>
              <ReclassifyForm fromAccountId={filters.account_id} accounts={accounts}
                lines={(account?.lines ?? []).map((l) => ({ ...l, movable: l.status === "posted" && l.source_type !== "reversal" }))} />
            </Card>
          )}
          <p className="mt-3 text-[13px] text-muted">
            Only accounts a manual journal may use are offered: receivables, payables, stock, undeposited funds and retained earnings are corrected through their documents.
          </p>
        </>
      )}
    </>
  );
}

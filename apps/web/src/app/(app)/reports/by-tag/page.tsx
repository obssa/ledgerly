import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getTagReport } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { todayIn } from "@/lib/dates";
import { ReportsNav } from "../ReportsNav";

export const metadata = { title: "Profit by tag" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** Module 1 (094): income, expenses and profit of the transactions each tag is on, by account. */
export default async function ProfitByTagPage({ searchParams }: PageProps<"/reports/by-tag">) {
  const q = await searchParams;
  const today = todayIn();
  const to = date(q.to) ?? today;
  const from = date(q.from) ?? `${to.slice(0, 4)}-01-01`;
  const r = await getTagReport(from, to);

  return (
    <>
      <PageTitle eyebrow="Reports / Management" title="Profit by tag" actions={<Link href="/tags" className="btn">Manage tags</Link>} />
      <form className="mb-3 flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/by-tag" />
        <div className="min-w-0 flex-[4_1_560px]">
          {!r ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : r.data.length === 0 ? (
            <Card label="Profit by tag"><p className="text-sm text-muted">No tags yet. Add them under Tags, then put them on invoices, bills, expenses and journal entries.</p></Card>
          ) : r.data.map((t) => (
            <Card key={t.id} className="mb-4 !p-2" label={t.name}>
              <div className="flex flex-wrap items-baseline justify-between gap-2 px-3 pt-2">
                <h2 className="text-[15px] font-bold">{t.name}{!t.is_active && <span className="ml-1 text-[12px] font-normal text-muted">(off)</span>}</h2>
                <span className="text-[13px] text-muted">{t.transactions} transaction{t.transactions === 1 ? "" : "s"}</span>
              </div>
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">Amount</th></tr></thead>
                  <tbody>
                    {t.accounts.map((a) => (
                      <tr key={a.account_id}><td><span className="num">{a.code}</span> {a.name}</td><td className="num text-right">{formatAmount(a.amount)}<span className="ml-1 text-[12px] text-muted">{a.type === "income" ? "in" : "out"}</span></td></tr>
                    ))}
                    <tr><td>Income</td><td className="num text-right">{formatAmount(t.income)}</td></tr>
                    <tr><td>Expenses</td><td className="num text-right">{formatAmount(t.expenses)}</td></tr>
                    <tr className="font-semibold"><td>Profit</td><td className={`num text-right ${Number(t.profit) < 0 ? "text-bad" : ""}`}>{formatAmount(t.profit)}</td></tr>
                  </tbody>
                </table>
              </div>
            </Card>
          ))}
          <p className="text-[13px] text-muted">From the ledger: a tagged document&rsquo;s own entries (a corrected one included), tagged journal entries, and their reversals. A transaction with two tags counts under each.</p>
        </div>
      </div>
    </>
  );
}

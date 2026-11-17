import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getChart, getDimensions, getGeneralLedger, getCompanyToday } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink } from "../ReportsNav";
import { defaultPeriod, drCr } from "@/lib/ledger";

export const metadata = { title: "General ledger" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);
const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

function Balance({ value }: { value: string }) {
  const b = drCr(value);
  return <>{formatAmount(b.amount)}{b.side && <span className="ml-1 text-[12px] text-muted">{b.side}</span>}</>;
}

/** Module 6: every posted line of a period, account by account, with the balance carried forward and a running balance. */
export default async function GeneralLedgerPage({ searchParams }: PageProps<"/reports/general-ledger">) {
  const q = await searchParams;
  const period = defaultPeriod(await getCompanyToday());
  const filters = {
    from: date(q.from) ?? period.from, to: date(q.to) ?? period.to,
    account_id: uuid(q.account_id), location_id: uuid(q.location_id), class_id: uuid(q.class_id), project_id: uuid(q.project_id),
  };
  const [gl, chart, dimensions] = await Promise.all([getGeneralLedger(filters), getChart(true), getDimensions()]);
  const kinds = [["location", "location_id", "Location"], ["class", "class_id", "Class"], ["project", "project_id", "Project"]] as const;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Ledger</>} title="General ledger"
        actions={<ExportLink report="general-ledger" params={{ from: filters.from, to: filters.to, account_id: filters.account_id }} />} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={filters.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={filters.to} /></div>
        <div className="min-w-[220px]">
          <label className="field-label" htmlFor="account_id">Account</label>
          <select className="field" id="account_id" name="account_id" defaultValue={filters.account_id ?? ""}>
            <option value="">Every account</option>
            {chart.data.map((a) => <option key={a.id} value={a.id}>{`${a.code} ${a.name}`}</option>)}
          </select>
        </div>
        {kinds.filter(([kind]) => dimensions.some((d) => d.kind === kind)).map(([kind, column, label]) => (
          <div key={kind}>
            <label className="field-label" htmlFor={column}>{label}</label>
            <select className="field" id={column} name={column} defaultValue={filters[column] ?? ""}>
              <option value="">All</option>
              {dimensions.filter((d) => d.kind === kind).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
        ))}
        <button type="submit" className="btn">Show</button>
      </form>
      {!gl && <p className="text-[14px] text-muted">{chart.demo ? "Not available in the demo." : "Check the dates: the period ends on or after it starts."}</p>}
      {gl?.truncated && <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Only the first lines of a long period are shown: choose a shorter period or one account.</p>}
      {gl && gl.accounts.length === 0 && <p className="text-[14px] text-muted">Nothing posted in this period.</p>}
      {gl?.accounts.map((a) => (
        <Card key={a.account_id} className="mb-4 !p-2" label={`${a.code} ${a.name}`}>
          <div className="flex flex-wrap items-center justify-between gap-2 px-3 pt-2">
            <h2 className="text-[15px] font-bold"><Link href={`/accounts/${encodeURIComponent(a.account_id)}`} className="hover:underline"><span className="num">{a.code}</span> {a.name}</Link></h2>
            {a.lines.length > 0 && (
              <Link href={`/journal/reclassify?${new URLSearchParams({ account_id: a.account_id, from: gl.from, to: gl.to }).toString()}`} className="text-[13px] font-semibold text-brand hover:underline print:hidden">
                Reclassify lines
              </Link>
            )}
          </div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">What</th><th scope="col">Who</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th><th scope="col" className="!text-right">Balance</th></tr></thead>
              <tbody>
                <tr><td className="num">{gl.from}</td><td colSpan={5} className="font-semibold">Brought forward</td><td className="num text-right font-semibold"><Balance value={a.opening} /></td></tr>
                {a.lines.map((l, i) => (
                  <tr key={`${l.entry_id}-${i}`}>
                    <td className="num">{l.date}</td>
                    <td className="num"><Link href={`/journal/${encodeURIComponent(l.entry_id)}`} className="hover:underline">#{l.entry}</Link></td>
                    <td>{l.description ?? l.memo ?? l.source_type.replace(/_/g, " ")}</td>
                    <td>{l.contact ?? ""}</td>
                    <td className="num text-right">{l.debit === "0.00" ? "" : formatAmount(l.debit)}</td>
                    <td className="num text-right">{l.credit === "0.00" ? "" : formatAmount(l.credit)}</td>
                    <td className="num text-right"><Balance value={l.balance} /></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-ink font-bold">
                  <td colSpan={4}>Balance at {gl.to}</td>
                  <td className="num text-right">{formatAmount(a.debits)}</td><td className="num text-right">{formatAmount(a.credits)}</td>
                  <td className="num text-right"><Balance value={a.closing} /></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </Card>
      ))}
    </>
  );
}

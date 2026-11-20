import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getJournal, getJournalRequests } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Journal entries" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);
const SOURCES = [["", "Everything"], ["manual", "Manual journals"], ["invoice", "Invoices"], ["bill", "Bills"], ["expense", "Expenses"], ["payment", "Payments"],
  ["transfer", "Transfers"], ["deposit", "Deposits"], ["stock_adjustment", "Stock adjustments"], ["reversal", "Reversals"], ["opening", "Opening balances"], ["closing", "Year-end close"], ["adjustment", "Audit adjustments"]] as const;

/** Module 6: every entry of the books, newest first, by date, source or memo; manual journals posted from here. */
export default async function JournalPage({ searchParams }: PageProps<"/journal">) {
  const q = await searchParams;
  const filters = {
    from: date(q.from), to: date(q.to),
    source: typeof q.source === "string" && /^[a-z_]+$/.test(q.source) ? q.source : undefined,
    search: typeof q.search === "string" && q.search.trim() ? q.search.trim().slice(0, 100) : undefined,
    page: typeof q.page === "string" && /^\d{1,4}$/.test(q.page) ? q.page : undefined,
  };
  const [journal, requests] = await Promise.all([getJournal(filters), getJournalRequests()]);
  const pageHref = (page: number) => `/journal?${new URLSearchParams(Object.entries({ ...filters, page: String(page) }).filter(([, v]) => v) as [string, string][]).toString()}`;

  return (
    <>
      <PageTitle eyebrow="Books" title="Journal entries" actions={<>
        {journal.can.create && <Link href="/journal/new" className="btn btn-primary">New journal entry</Link>}
      </>} />
      {requests.data.length > 0 && (
        <Card className="mb-4 !p-2" label="Waiting for approval">
          <h2 className="px-3 pt-2 text-[15px] font-bold">Waiting for approval ({requests.pending})</h2>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Date</th><th scope="col">Memo</th><th scope="col">Asked by</th><th scope="col" className="!text-right">Amount</th></tr></thead>
              <tbody>
                {requests.data.map((r) => (
                  <tr key={r.id}>
                    <td className="num">{r.date}</td>
                    <td><Link href={`/journal/requests/${encodeURIComponent(r.id)}`} className="font-semibold hover:underline">{r.memo}</Link></td>
                    <td>{r.requested_by ?? "—"}</td>
                    <td className="num text-right">{formatAmount(r.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      <form className="mb-4 flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={filters.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={filters.to} /></div>
        <div>
          <label className="field-label" htmlFor="source">Made by</label>
          <select className="field" id="source" name="source" defaultValue={filters.source ?? ""}>{SOURCES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </div>
        <div><label className="field-label" htmlFor="search">Memo contains</label><input className="field" id="search" name="search" defaultValue={filters.search} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <Card className="!p-2" label="Entries">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Memo</th><th scope="col">Made by</th><th scope="col">Posted by</th><th scope="col" className="!text-right">Amount</th></tr></thead>
            <tbody>
              {journal.data.length === 0 && <tr><td colSpan={6} className="text-muted">{journal.demo ? "Not available in the demo." : "No entries."}</td></tr>}
              {journal.data.map((e) => (
                <tr key={e.id}>
                  <td className="num font-semibold"><Link href={`/journal/${encodeURIComponent(e.id)}`} className="hover:underline">#{e.number}</Link></td>
                  <td className="num">{e.date}</td>
                  <td>{e.memo ?? "—"}{e.status === "reversed" && <span className="ml-2"><Pill>Reversed</Pill></span>}</td>
                  <td className="text-[13px] text-muted">{e.source_type.replace(/_/g, " ")}</td>
                  <td className="text-[13px]">{e.posted_by ?? "—"}</td>
                  <td className="num text-right">{formatAmount(e.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <nav aria-label="Pages" className="mt-3 flex gap-2">
        {journal.page > 1 && <Link href={pageHref(journal.page - 1)} className="btn">Newer</Link>}
        {journal.more && <Link href={pageHref(journal.page + 1)} className="btn">Older</Link>}
      </nav>
    </>
  );
}

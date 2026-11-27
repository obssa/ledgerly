import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getRecurringJournals } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { FREQUENCIES } from "@/lib/journal";
import { PostDue } from "../JournalForms";

export const metadata = { title: "Recurring journals" };

const REPEATS = Object.fromEntries(FREQUENCIES) as Record<string, string>;

/** Module 6: manual journals posted on a schedule (rent, depreciation, a standing accrual), posted every hour as they come due. */
export default async function RecurringJournalsPage() {
  const list = await getRecurringJournals();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal" className="hover:underline">Journal entries</Link> / Recurring</>} title="Recurring journals"
        actions={list.can.create ? <Link href="/journal/recurring/new" className="btn btn-primary">New recurring journal</Link> : undefined} />
      {list.can.create && (
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <PostDue due={list.due} />
          <span className="text-[13px] text-muted">What is due also posts by itself within the hour.</span>
        </div>
      )}
      <Card className="!p-2" label="Recurring journals">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Name</th><th scope="col">Repeats</th><th scope="col">Next entry</th><th scope="col">Ends</th>
                <th scope="col" className="!text-right">Posted</th><th scope="col" className="!text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {list.data.length === 0 && <tr><td colSpan={6} className="text-muted">{list.demo ? "Not available in the demo." : "No recurring journals yet."}</td></tr>}
              {list.data.map((t) => (
                <tr key={t.id}>
                  <td>
                    <Link href={`/journal/recurring/${encodeURIComponent(t.id)}`} className="font-semibold hover:underline">{t.name}</Link>
                    {!t.is_active && <span className="ml-2"><Pill tone={t.last_error ? "bad" : "neutral"}>{t.last_error ? "Paused: needs attention" : "Paused"}</Pill></span>}
                  </td>
                  <td>{REPEATS[t.frequency] ?? t.frequency}</td>
                  <td className="num">{t.is_active ? t.next_on : "—"}</td>
                  <td className="num"><Dt v={t.ends_on} /></td>
                  <td className="num text-right">{t.posted_count}</td>
                  <td className="num text-right">{formatAmount(t.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

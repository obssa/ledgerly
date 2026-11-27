import { Dt } from "@/components/Dt";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { CLOSED_RANGE_PRESETS } from "@/lib/periods";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { ReviewFlag } from "@/lib/api";
import { getReviewQueue, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ReviewEntry } from "../JournalForms";

export const metadata = { title: "Review queue" };

/** Short names for the reasons an entry is in the queue; the API gives the full sentence. */
const LABELS: Record<ReviewFlag, string> = {
  manual_cash: "Cash moved by a journal", round_amount: "Large round amount", large: "Unusually large", backdated: "Posted late",
  out_of_hours: "Posted at night", opening_equity: "Opening equity",
};

/** Module 6, accountant tools: entries worth a second look, why, and marking them reviewed. Nothing is blocked. */
export default async function ReviewQueuePage({ searchParams }: PageProps<"/journal/review">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "last_90_days", CLOSED_RANGE_PRESETS);
  const filters = { from: period.from, to: period.to, reviewed: q.reviewed === "1" ? "1" : undefined };
  const queue = await getReviewQueue(filters);

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal" className="hover:underline">Journal entries</Link> / Review</>} title="Review queue" />
      {!queue ? <p className="text-[14px] text-muted">Not available in the demo, or check the dates.</p> : (
        <>
          <DateRangeFilter picked={period.picked} keep={q} allowed={CLOSED_RANGE_PRESETS} fields={["reviewed"]}>
            <label className="flex items-center gap-2 pb-2 text-[14px]"><input type="checkbox" name="reviewed" value="1" defaultChecked={filters.reviewed === "1"} /> Show reviewed entries too</label>
      </DateRangeFilter>
          <Card className="mb-4" label="Why entries are here">
            <ul className="grid gap-1.5 text-[14px]">
              {(Object.keys(LABELS) as ReviewFlag[]).map((f) => (
                <li key={f} className="flex flex-wrap items-baseline gap-2">
                  <Pill tone={queue.counts[f] > 0 ? "warn" : "neutral"}>{LABELS[f]} <span className="num">{queue.counts[f]}</span></Pill>
                  <span className="text-muted">{queue.flags[f]}</span>
                </li>
              ))}
            </ul>
          </Card>
          {queue.truncated && <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Only the latest entries of a long period are checked: choose a shorter period.</p>}
          <Card className="!p-2" label="Entries to review">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr>
                    <th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Memo</th><th scope="col">Why</th><th scope="col">Posted</th>
                    <th scope="col" className="!text-right">Amount</th><th scope="col">Review</th>
                  </tr>
                </thead>
                <tbody>
                  {queue.data.length === 0 && <tr><td colSpan={7} className="text-muted">Nothing to review in this period.</td></tr>}
                  {queue.data.map((e) => (
                    <tr key={e.id}>
                      <td className="num font-semibold"><Link href={`/journal/${encodeURIComponent(e.id)}`} className="hover:underline">#{e.number}</Link></td>
                      <td className="num"><Dt v={e.date} /></td>
                      <td>{e.memo ?? e.source_type.replace(/_/g, " ")}{e.status === "reversed" && <span className="ml-2"><Pill>Reversed</Pill></span>}</td>
                      <td><span className="flex flex-wrap gap-1">{e.flags.map((f) => <Pill key={f} tone="warn">{LABELS[f] ?? f}</Pill>)}</span></td>
                      <td className="text-[13px]">{e.posted_by ?? "—"}<span className="block num text-muted"><Dt v={e.posted_at} time /></span></td>
                      <td className="num text-right">{formatAmount(e.total)}</td>
                      <td>
                        {e.review ? (
                          <span className="text-[13px]">By {e.review.by ?? "—"}{e.review.note && <span className="block text-muted">{e.review.note}</span>}</span>
                        ) : queue.can.review ? <ReviewEntry id={e.id} number={e.number} /> : <span className="text-[13px] text-muted">Not reviewed</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
          <p className="mt-3 text-[13px] text-muted">The queue only points: an entry here may be right. Mark it reviewed, with a note, once you have looked at it.</p>
        </>
      )}
    </>
  );
}

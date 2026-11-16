import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getJournalOptions, getRecurringJournal } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { FREQUENCIES, journalAccounts } from "@/lib/journal";
import { RecurringForm, RecurringStep } from "../../JournalForms";

export const metadata = { title: "Recurring journal" };

const REPEATS = Object.fromEntries(FREQUENCIES) as Record<string, string>;

/** Module 6: one recurring journal, the entries it has posted, and pausing, resuming or editing it. */
export default async function RecurringJournalPage({ params }: PageProps<"/journal/recurring/[id]">) {
  const { id } = await params;
  const [t, options] = await Promise.all([getRecurringJournal(id), getJournalOptions()]);
  if (!t) notFound();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal/recurring" className="hover:underline">Recurring journals</Link> / {t.name}</>}
        title={<>{t.name} <Pill tone={t.is_active ? "good" : t.last_error ? "bad" : "neutral"}>{t.is_active ? "Running" : "Paused"}</Pill></>} />
      {t.last_error && (
        <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">Paused because: {t.last_error}</p>
      )}
      <Card className="mb-4" label="Schedule">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Repeats</dt><dd>{REPEATS[t.frequency] ?? t.frequency}</dd></div>
          <div><dt className="text-muted">Next entry</dt><dd className="num">{t.is_active ? t.next_on : "—"}</dd></div>
          <div><dt className="text-muted">Last entry by</dt><dd className="num">{t.ends_on ?? "No end"}</dd></div>
          <div><dt className="text-muted">Amount</dt><dd className="num font-semibold">{formatAmount(t.total)}</dd></div>
          <div><dt className="text-muted">Posted</dt><dd className="num">{t.posted_count}{t.last_posted_on ? `, last for ${t.last_posted_on}` : ""}</dd></div>
          <div><dt className="text-muted">Posts as</dt><dd>{t.posts_as ?? "—"}</dd></div>
        </dl>
        {t.can.edit && <div className="mt-4"><RecurringStep id={t.id} active={t.is_active} nextOn={t.next_on} /></div>}
      </Card>
      <Card className="mb-4 !p-2" label="Entries posted">
        <h2 className="px-3 pt-2 text-[15px] font-bold">Entries posted</h2>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Status</th></tr></thead>
            <tbody>
              {(t.entries ?? []).length === 0 && <tr><td colSpan={3} className="text-muted">Nothing posted yet.</td></tr>}
              {(t.entries ?? []).map((e) => (
                <tr key={e.id}>
                  <td className="num font-semibold"><Link href={`/journal/${encodeURIComponent(e.id)}`} className="hover:underline">#{e.number}</Link></td>
                  <td className="num">{e.date}</td>
                  <td>{e.status === "reversed" ? <Pill>Reversed</Pill> : "Posted"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {t.can.edit && options ? (
        <Card label="Edit">
          <h2 className="mb-3 text-[15px] font-bold">Edit</h2>
          <RecurringForm accounts={journalAccounts(options.accounts, options.baseCurrency)} dimensions={options.dimensions} template={t} />
        </Card>
      ) : (
        <Card className="!p-2" label="Lines">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Account</th><th scope="col">Description</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></tr></thead>
              <tbody>
                {(t.lines ?? []).map((l, i) => (
                  <tr key={i}>
                    <td><span className="num">{l.code}</span> {l.account}</td>
                    <td>{l.description ?? ""}</td>
                    <td className="num text-right">{l.debit ? formatAmount(l.debit) : ""}</td>
                    <td className="num text-right">{l.credit ? formatAmount(l.credit) : ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  );
}

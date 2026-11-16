import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getJournalOptions } from "@/lib/data";
import { journalAccounts } from "@/lib/journal";
import { NewJournal } from "../JournalForms";

export const metadata = { title: "New journal entry" };

/** Module 6: post a manual journal entry (an accrual, a correction, a reclassification). */
export default async function NewJournalPage() {
  const options = await getJournalOptions();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal" className="hover:underline">Journal entries</Link> / New</>} title="New journal entry" />
      {!options ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card label="Entry">
          <NewJournal accounts={journalAccounts(options.accounts, options.baseCurrency)} dimensions={options.dimensions} />
          <p className="mt-4 text-[13px] text-muted">
            Receivables, payables, stock, undeposited funds and retained earnings are kept by their documents and are not offered here:
            correct them with a credit note, a stock adjustment or a deposit.
          </p>
        </Card>
      )}
    </>
  );
}

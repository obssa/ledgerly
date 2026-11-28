import { Card, PageTitle } from "@/components/ui";
import { getJournalOptions } from "@/lib/data";
import { journalAccounts } from "@/lib/journal";
import { RecurringForm } from "../../JournalForms";

export const metadata = { title: "New recurring journal" };

/** Module 6: set up a manual journal that posts on a schedule. */
export default async function NewRecurringJournalPage() {
  const options = await getJournalOptions();

  return (
    <>
      <PageTitle crumbs={<>New</>} title="New recurring journal" />
      {!options ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card label="Recurring journal">
          <RecurringForm accounts={journalAccounts(options.accounts, options.baseCurrency)} dimensions={options.dimensions} />
          <p className="mt-4 text-[13px] text-muted">
            A date late in a month stays late: an entry on the 31st posts on the last day of shorter months, and a month-end date stays at month-end.
          </p>
        </Card>
      )}
    </>
  );
}

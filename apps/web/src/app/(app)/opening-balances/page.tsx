import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getOpeningBalances } from "@/lib/data";
import { OpeningBalancesForm } from "./OpeningBalancesForm";

export const metadata = { title: "Opening balances" };

/**
 * Module 1, opening balances: the trial balance on the conversion date (the last bank statement date before
 * Ledgerly). Receivables, payables and stock come from the open invoices, bills and stock behind them.
 */
export default async function OpeningBalancesPage() {
  const data = await getOpeningBalances();

  if (data === null) {
    return (
      <>
        <PageTitle eyebrow="Books" title="Opening balances" />
        <Card className="max-w-[820px]"><p className="text-sm text-muted">Not available in the demo.</p></Card>
      </>
    );
  }

  const { opening, accounts } = data;
  const usable = accounts.filter(
    (a) => a.is_active && !opening.from_detail.includes(a.system_key ?? "") && (a.currency === null || a.currency === opening.base_currency),
  );

  return (
    <>
      <PageTitle
        eyebrow="Books"
        title="Opening balances"
        actions={opening.journal_entry ? <Pill tone="good">Posted as entry {opening.journal_entry.number}</Pill> : <Pill tone="warn">Not entered yet</Pill>}
      />
      <Card className="max-w-[1000px]" label="Opening trial balance">
        {opening.conversion_date === null ? (
          <p className="text-sm">
            Set the conversion date first: it is the date of your last bank statement before Ledgerly, and opening balances are as at that
            date. <Link href="/settings" className="font-bold text-brand">Company settings</Link>
          </p>
        ) : (
          <>
            <p className="max-w-[720px] text-sm text-muted">
              Copy each balance from your old system&apos;s trial balance as at <strong className="num text-ink">{opening.conversion_date}</strong>,
              in {opening.base_currency}. If debits and credits differ, the difference goes to Opening balance equity, so the books
              balance and the difference stays visible. Entering them again replaces the previous figures; the change is kept in the
              history. Customer and supplier balances and stock are entered from their open invoices, bills and stock counts.
            </p>
            <OpeningBalancesForm opening={opening} accounts={usable} />
          </>
        )}
      </Card>
    </>
  );
}

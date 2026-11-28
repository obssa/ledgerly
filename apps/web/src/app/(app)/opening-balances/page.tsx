import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getOpeningBalances } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { OpeningBalancesForm } from "./OpeningBalancesForm";
import { OpeningDocumentForm } from "./OpeningDocumentForm";

export const metadata = { title: "Opening balances" };

/**
 * Module 1, opening balances: the trial balance on the conversion date (the last bank statement date before
 * Evenbooks). Receivables, payables and stock come from the open invoices, bills and stock behind them.
 */
export default async function OpeningBalancesPage() {
  const data = await getOpeningBalances();

  if (data === null) {
    return (
      <>
        <PageTitle title="Opening balances" />
        <Card className="max-w-[820px]"><p className="text-sm text-muted">Not available in the demo.</p></Card>
      </>
    );
  }

  const { opening, accounts, customers, suppliers, currencies } = data;
  const documents = opening.documents ?? [];
  const usable = accounts.filter(
    (a) => a.is_active && !opening.from_detail.includes(a.system_key ?? "") && (a.currency === null || a.currency === opening.base_currency),
  );

  return (
    <>
      <PageTitle
       
        title="Opening balances"
        actions={opening.journal_entry ? <Pill tone="good">Posted as entry {opening.journal_entry.number}</Pill> : <Pill tone="warn">Not entered yet</Pill>}
      />
      <Card className="max-w-[1000px]" label="Opening trial balance">
        {opening.conversion_date === null ? (
          <p className="text-sm">
            Set the conversion date first: it is the date of your last bank statement before Evenbooks, and opening balances are as at that
            date. <Link href="/settings" className="font-bold text-brand">Company settings</Link>
          </p>
        ) : (
          <>
            <p className="max-w-[720px] text-sm text-muted">
              Copy each balance from your old system&apos;s trial balance as at <strong className="num text-ink"><Dt v={opening.conversion_date} /></strong>,
              in {opening.base_currency}. If debits and credits differ, the difference goes to Opening balance equity, so the books
              balance and the difference stays visible. Entering them again replaces the previous figures; the change is kept in the
              history. Customer and supplier balances and stock are entered from their open invoices, bills and stock counts.
            </p>
            <OpeningBalancesForm opening={opening} accounts={usable} />
          </>
        )}
      </Card>

      {opening.conversion_date !== null && (
        <Card className="mt-4 max-w-[1000px]" label="Open invoices and bills">
          <h2 className="text-[17px] font-bold">Open invoices and bills from the old books</h2>
          <p className="mt-1 max-w-[720px] text-sm text-muted">
            Enter each invoice a customer still owed, and each bill still owed to a supplier, on <Dt v={opening.conversion_date} />: its number,
            dates and the amount still open. It posts on the conversion date against opening balance equity (no sale, cost or tax again),
            is never sent to the tax authority, and is paid, aged and stated like any other. Many at once:{" "}
            <Link href="/import?kind=open-invoices" className="font-bold text-brand">import invoices</Link> or{" "}
            <Link href="/import?kind=open-bills" className="font-bold text-brand">bills</Link> from a spreadsheet.
          </p>
          {documents.length > 0 && (
            <div className="mt-3 overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Type</th><th scope="col">No.</th><th scope="col">Name</th><th scope="col">Date</th><th scope="col">Due date</th><th scope="col" className="!text-right">Open at conversion</th><th scope="col" className="!text-right">Open balance</th></tr></thead>
                <tbody>
                  {documents.map((d) => (
                    <tr key={d.id}>
                      <td>{d.type === "bill" ? "Bill" : "Invoice"}</td>
                      <td className="num font-semibold">
                        <Link href={`/${d.type === "bill" ? "bills" : "invoices"}/${encodeURIComponent(d.id)}`} className="hover:underline">{d.number}</Link>
                        {d.reference && <span className="text-[12px] text-muted"> ({d.reference})</span>}
                      </td>
                      <td>{d.contact?.name}</td>
                      <td className="num"><Dt v={d.issue_date} /></td>
                      <td className="num"><Dt v={d.due_date} /></td>
                      <td className="num text-right">{formatMoney(d.total, d.currency)}</td>
                      <td className="num text-right font-semibold">{formatMoney(d.amount_due, d.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {opening.can.enter && <OpeningDocumentForm conversionDate={opening.conversion_date} customers={customers} suppliers={suppliers} currencies={currencies} />}
        </Card>
      )}
    </>
  );
}

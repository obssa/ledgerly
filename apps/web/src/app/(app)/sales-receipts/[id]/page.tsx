import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getSalesReceipt } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { VoidSalesReceipt } from "./VoidSalesReceipt";

export const metadata = { title: "Cash sale" };

const METHOD: Record<string, string> = { cash: "Cash", card: "Card", bank_transfer: "Bank transfer", mobile_money: "Mobile money", cheque: "Cheque", other: "Other" };

/** One cash sale (070): the customer, where the money went, the lines and totals. */
export default async function SalesReceiptPage({ params }: PageProps<"/sales-receipts/[id]">) {
  const { id } = await params;
  const receipt = await getSalesReceipt(id);
  if (!receipt) notFound();
  const voided = receipt.status === "void";
  const submission = receipt.einvoice_submissions?.[0];

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/sales-receipts" className="hover:underline">Cash sales</Link> / Cash sale</>}
        title={<>Cash sale <span className="num">{receipt.number}</span> {voided ? <Pill>Void</Pill> : <Pill tone="good">Paid</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Cash sale">
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
            <div><dt className="text-muted">Customer</dt><dd><Link href={`/customers/${encodeURIComponent(receipt.contact.id)}`} className="font-bold hover:underline">{receipt.contact.name}</Link></dd></div>
            <div><dt className="text-muted">Date</dt><dd className="num"><Dt v={receipt.issue_date} /></dd></div>
            <div><dt className="text-muted">Slip</dt><dd className="num">{receipt.reference ?? "—"}</dd></div>
            <div><dt className="text-muted">Received into</dt><dd>{receipt.paid_from ? `${receipt.paid_from.code} ${receipt.paid_from.name}` : "—"}</dd></div>
            <div><dt className="text-muted">Paid by</dt><dd>{METHOD[receipt.payment_method] ?? receipt.payment_method}</dd></div>
            {Number(receipt.fx_rate) !== 1 && <div><dt className="text-muted">Rate</dt><dd className="num">{receipt.fx_rate}</dd></div>}
          </dl>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th><th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {(receipt.lines ?? []).map((l) => (
                  <tr key={l.line_no}>
                    <td>{l.description}</td>
                    <td className="num text-right">{l.quantity}</td>
                    <td className="num text-right">{formatAmount(l.unit_price)}</td>
                    <td className="num text-right">{formatAmount(l.line_tax)}</td>
                    <td className="num text-right font-semibold">{formatAmount(l.line_subtotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <dl className="ml-auto mt-4 max-w-[320px] text-sm">
            <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(receipt.subtotal)}</dd></div>
            <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(receipt.tax_total)}</dd></div>
            <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total received</dt><dd className="num">{formatMoney(receipt.total, receipt.currency)}</dd></div>
          </dl>
          {receipt.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{receipt.notes}</p>}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {!voided && receipt.can?.void && submission?.status !== "cleared" && <VoidSalesReceipt id={receipt.id} />}
          {!voided && receipt.can?.credit && (
            <Link href={`/sales-receipts/${encodeURIComponent(receipt.id)}/credit-note`} className="btn justify-center">Issue a credit note</Link>
          )}
          {submission && (
            <Card label="E-invoice">
              <h2 className="text-[17px] font-bold">E-invoice</h2>
              <p className="mt-1.5 text-sm">
                {submission.status === "cleared" ? <>Registered · <span className="num">{submission.clearance_number}</span></> :
                  submission.status === "rejected" ? <span className="text-bad">Rejected: {submission.last_error}</span> : "Queued for the tax authority."}
              </p>
              {submission.status === "cleared" && <p className="mt-1 text-[13px] text-muted">Registered, it is corrected with a credit note rather than voided.</p>}
            </Card>
          )}
          {receipt.journal_entry_id && <Link href={`/journal/${encodeURIComponent(receipt.journal_entry_id)}`} className="btn justify-center">See its journal entry</Link>}
        </aside>
      </div>
    </>
  );
}

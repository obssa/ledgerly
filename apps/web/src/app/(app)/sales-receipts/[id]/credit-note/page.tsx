import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { PageTitle } from "@/components/ui";
import { getInvoiceOptions, getSalesReceipt } from "@/lib/data";
import { CreditNoteForm } from "../../../invoices/[id]/credit-note/CreditNoteForm";

export const metadata = { title: "New credit note" };

/** 082: a credit note for a cash sale, the way a registered one is corrected; the credit stays as the customer's. */
export default async function CashSaleCreditNotePage({ params }: PageProps<"/sales-receipts/[id]/credit-note">) {
  const { id } = await params;
  const [receipt, options] = await Promise.all([getSalesReceipt(id), getInvoiceOptions()]);
  if (!receipt) notFound();

  return (
    <>
      <PageTitle
        crumbs={<><Link href={`/sales-receipts/${encodeURIComponent(receipt.id)}`} className="hover:underline">Cash sale <span className="num">{receipt.number}</span></Link> › Credit note</>}
        title={<>Credit note for <span className="num">{receipt.number}</span></>}
      />
      {receipt.status !== "void" ? (
        <>
          <p className="mb-4 max-w-[760px] text-sm text-muted">The sale was paid on the spot, so the credit note leaves the amount as the customer&apos;s credit: refund it from the credit note, or apply it to one of their invoices.</p>
          <CreditNoteForm invoice={{ ...receipt, lines: receipt.lines ?? [] }} taxRates={options.taxRates} projects={options.projects ?? []} />
        </>
      ) : (
        <p className="text-sm text-muted">A void cash sale is not credited.</p>
      )}
    </>
  );
}

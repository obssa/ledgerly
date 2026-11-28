import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { PageTitle } from "@/components/ui";
import { getInvoice, getInvoiceOptions } from "@/lib/data";
import { CreditNoteForm } from "./CreditNoteForm";

export const metadata = { title: "New credit note" };

export default async function NewCreditNotePage({ params }: PageProps<"/invoices/[id]/credit-note">) {
  const { id } = await params;
  const [invoice, options] = await Promise.all([getInvoice(id), getInvoiceOptions()]);
  if (!invoice || invoice.type !== "invoice") notFound();

  return (
    <>
      <PageTitle
        crumbs={<><Link href={`/invoices/${encodeURIComponent(invoice.id)}`} className="hover:underline">Invoice <span className="num">{invoice.number}</span></Link> › Credit note</>}
        title={<>Credit note for <span className="num">{invoice.number}</span></>}
      />
      {["posted", "part_paid", "paid"].includes(invoice.status) ? (
        <CreditNoteForm invoice={invoice} taxRates={options.taxRates} projects={options.projects ?? []} />
      ) : (
        <p className="text-sm text-muted">Only a posted invoice is credited. Change the draft instead.</p>
      )}
    </>
  );
}

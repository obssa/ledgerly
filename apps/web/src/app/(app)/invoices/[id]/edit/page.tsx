import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { PageTitle, Pill } from "@/components/ui";
import { getInvoice, getInvoiceOptions } from "@/lib/data";
import { InvoiceForm } from "../../new/InvoiceForm";

export const metadata = { title: "Edit draft invoice" };

/**
 * Module 2: a draft (or one awaiting approval) is corrected in the same form it was made in, filled with what was saved.
 * The save names the version it was read at, so it is refused if someone else changed the draft in the meantime. A
 * posted invoice is not edited here: it is changed with a reason, through its history.
 */
export default async function EditInvoicePage({ params }: PageProps<"/invoices/[id]/edit">) {
  const { id } = await params;
  const [invoice, options] = await Promise.all([getInvoice(id), getInvoiceOptions()]);
  if (!invoice || invoice.type !== "invoice") notFound();
  if (!["draft", "awaiting_approval"].includes(invoice.status) || !invoice.can.edit || invoice.demo) redirect(`/invoices/${encodeURIComponent(id)}`);

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / <Link href={`/invoices/${encodeURIComponent(id)}`} className="hover:underline">Draft</Link> / Edit</>}
        title={<>Edit invoice <Pill>Draft</Pill></>}
      />
      <InvoiceForm options={options} draft={invoice} />
    </>
  );
}

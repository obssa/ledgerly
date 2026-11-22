import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { PageTitle, Pill } from "@/components/ui";
import { getInvoice, getInvoiceOptions } from "@/lib/data";
import { InvoiceForm } from "../../new/InvoiceForm";

export const metadata = { title: "Edit invoice" };

/**
 * Module 2: a draft (or one awaiting approval) is changed in the same form it was made in, filled with what was saved.
 * A posted invoice is corrected here too, with a reason, when the API says it can be (nothing paid, not registered
 * with the tax authority…): it reverses the entry, posts the corrected one and keeps the old version. The save
 * names the version it was read at, so it is refused if someone else changed it in the meantime.
 */
export default async function EditInvoicePage({ params }: PageProps<"/invoices/[id]/edit">) {
  const { id } = await params;
  const [invoice, options] = await Promise.all([getInvoice(id), getInvoiceOptions()]);
  if (!invoice || invoice.type !== "invoice") notFound();
  const back = `/invoices/${encodeURIComponent(id)}`;
  const draft = ["draft", "awaiting_approval"].includes(invoice.status);
  if (invoice.demo || (draft ? !invoice.can.edit : !invoice.can.correct)) redirect(back);

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / <Link href={back} className="hover:underline">{invoice.number ?? "Draft"}</Link> / Edit</>}
        title={draft ? <>Edit invoice <Pill>Draft</Pill></> : <>Edit invoice <span className="num">{invoice.number}</span> <Pill tone="brand">Posted</Pill></>}
      />
      <InvoiceForm options={options} draft={invoice} />
    </>
  );
}

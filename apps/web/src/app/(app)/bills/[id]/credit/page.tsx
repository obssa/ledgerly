import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { PageTitle } from "@/components/ui";
import { getBill, getBillOptions } from "@/lib/data";
import { CreditNoteForm } from "../../../invoices/[id]/credit-note/CreditNoteForm";

export const metadata = { title: "New supplier credit" };

/** What a supplier gives back on one posted bill: starts as a copy of the bill's lines. */
export default async function NewSupplierCreditPage({ params }: PageProps<"/bills/[id]/credit">) {
  const { id } = await params;
  const [bill, options] = await Promise.all([getBill(id), getBillOptions()]);
  if (!bill || bill.type !== "bill") notFound();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href={`/bills/${encodeURIComponent(bill.id)}`} className="hover:underline">Bill <span className="num">{bill.number}</span></Link> / Supplier credit</>}
        title={<>Supplier credit for <span className="num">{bill.number}</span></>}
      />
      {["posted", "part_paid", "paid"].includes(bill.status) ? (
        <CreditNoteForm invoice={bill} taxRates={options.taxRates} />
      ) : (
        <p className="text-sm text-muted">Only a posted bill is credited. Change the draft instead.</p>
      )}
    </>
  );
}

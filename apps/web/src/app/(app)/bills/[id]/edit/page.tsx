import Link from "@/components/Link";
import { notFound, redirect } from "next/navigation";
import { PageTitle, Pill } from "@/components/ui";
import { getBill, getBillOptions } from "@/lib/data";
import { InvoiceForm } from "../../../invoices/new/InvoiceForm";

export const metadata = { title: "Edit bill" };

/**
 * Module 3: a draft bill is changed in the form it was made in; a posted bill is corrected there with a reason when
 * the API says it can be (nothing paid, no goods received against it…): the entry is reversed and reposted and the
 * old version kept. Supplier credits are changed through their own page.
 */
export default async function EditBillPage({ params }: PageProps<"/bills/[id]/edit">) {
  const { id } = await params;
  const [bill, options] = await Promise.all([getBill(id), getBillOptions()]);
  if (!bill || bill.type !== "bill") notFound();
  const back = `/bills/${encodeURIComponent(id)}`;
  const draft = ["draft", "awaiting_approval"].includes(bill.status);
  if (bill.demo || (draft ? !bill.can.edit : !bill.can.correct)) redirect(back);

  return (
    <>
      <PageTitle
        crumbs={<><Link href={back} className="hover:underline">{bill.number ?? "Draft"}</Link> › Edit</>}
        title={draft ? <>Edit bill <Pill>Draft</Pill></> : <>Edit bill <span className="num">{bill.number}</span> <Pill tone="brand">Posted</Pill></>}
      />
      <InvoiceForm options={options} kind="bill" draft={bill as unknown as Parameters<typeof InvoiceForm>[0]["draft"]} />
    </>
  );
}

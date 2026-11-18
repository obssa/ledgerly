import Link from "next/link";
import { PageTitle } from "@/components/ui";
import { getSalesReceiptOptions } from "@/lib/data";
import { InvoiceForm } from "../../invoices/new/InvoiceForm";

export const metadata = { title: "New cash sale" };

export default async function NewSalesReceiptPage() {
  const options = await getSalesReceiptOptions();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/sales-receipts" className="hover:underline">Cash sales</Link> / New</>} title="Record a cash sale" />
      <InvoiceForm options={options} kind="sales_receipt" />
    </>
  );
}

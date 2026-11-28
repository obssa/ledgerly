import { PageTitle, Pill } from "@/components/ui";
import { getBillOptions } from "@/lib/data";
import { InvoiceForm } from "../../invoices/new/InvoiceForm";

export const metadata = { title: "New bill" };

export default async function NewBillPage() {
  const options = await getBillOptions();

  return (
    <>
      <PageTitle title={<>New bill <Pill>Draft</Pill></>} />
      <InvoiceForm options={options} kind="bill" />
    </>
  );
}

import { PageTitle, Pill } from "@/components/ui";
import { getInvoiceOptions } from "@/lib/data";
import { InvoiceForm } from "./InvoiceForm";

export const metadata = { title: "New invoice" };

export default async function NewInvoicePage() {
  const options = await getInvoiceOptions();

  return (
    <>
      <PageTitle title={<>New invoice <Pill>Draft</Pill></>} />
      <InvoiceForm options={options} />
    </>
  );
}

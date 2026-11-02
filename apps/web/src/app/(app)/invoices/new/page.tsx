import { PageTitle, Pill } from "@/components/ui";
import { demoTaxRates } from "@/lib/demo";
import { InvoiceForm } from "./InvoiceForm";

export const metadata = { title: "New invoice" };

export default function NewInvoicePage() {
  return (
    <>
      <PageTitle
        eyebrow="Sales and invoices / New invoice"
        title={<>Invoice <span className="num font-semibold">INV-2019-0419</span> <Pill>Draft</Pill></>}
      />
      <InvoiceForm taxRates={demoTaxRates} currency="ETB" />
    </>
  );
}

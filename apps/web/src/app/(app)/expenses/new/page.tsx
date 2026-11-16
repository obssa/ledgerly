import { PageTitle } from "@/components/ui";
import { getExpenseOptions } from "@/lib/data";
import { InvoiceForm } from "../../invoices/new/InvoiceForm";

export const metadata = { title: "New expense" };

export default async function NewExpensePage() {
  const options = await getExpenseOptions();

  return (
    <>
      <PageTitle eyebrow="Expenses / New expense" title="Record an expense" />
      <InvoiceForm options={options} kind="expense" />
    </>
  );
}

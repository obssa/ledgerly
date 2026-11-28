import { Card, PageTitle } from "@/components/ui";
import { getSupplierChoices } from "@/lib/data";
import { ItemForm } from "../ItemForms";

export const metadata = { title: "New product or service" };

export default async function NewItemPage() {
  const suppliers = await getSupplierChoices();
  return (
    <>
      <PageTitle title="New product or service" />
      <Card className="max-w-[900px]" label="New product or service"><ItemForm suppliers={suppliers} /></Card>
    </>
  );
}

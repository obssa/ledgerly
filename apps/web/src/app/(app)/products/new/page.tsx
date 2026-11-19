import { Card, PageTitle } from "@/components/ui";
import { getSuppliers } from "@/lib/data";
import { ItemForm } from "../ItemForms";

export const metadata = { title: "New item" };

export default async function NewItemPage() {
  const suppliers = await getSuppliers({});
  return (
    <>
      <PageTitle eyebrow="Products and services" title="New item" />
      <Card className="max-w-[900px]" label="New item"><ItemForm suppliers={suppliers.data.map((s) => ({ id: s.id, name: s.name }))} /></Card>
    </>
  );
}

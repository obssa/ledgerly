import { Card, PageTitle } from "@/components/ui";
import { ItemForm } from "../ItemForms";

export const metadata = { title: "New item" };

export default function NewItemPage() {
  return (
    <>
      <PageTitle eyebrow="Products and services" title="New item" />
      <Card className="max-w-[900px]" label="New item"><ItemForm /></Card>
    </>
  );
}

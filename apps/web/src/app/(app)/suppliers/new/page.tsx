import { Card, PageTitle } from "@/components/ui";
import { getCostAccounts } from "@/lib/data";
import { SupplierForm } from "../SupplierForm";

export const metadata = { title: "New supplier" };

export default async function NewSupplierPage() {
  const accounts = await getCostAccounts();

  return (
    <>
      <PageTitle eyebrow="Money out / Suppliers" title="New supplier" />
      <Card className="max-w-[900px]" label="New supplier">
        <SupplierForm accounts={accounts} />
      </Card>
    </>
  );
}

import { Card, PageTitle } from "@/components/ui";
import { isDemoMode } from "@/lib/api";
import { getCostAccounts, getPaymentTerms } from "@/lib/data";
import { SupplierForm } from "../SupplierForm";

export const metadata = { title: "New supplier" };

export default async function NewSupplierPage() {
  const [accounts, terms] = await Promise.all([getCostAccounts(), getPaymentTerms(true)]);

  return (
    <>
      <PageTitle title="New supplier" />
      <Card className="max-w-[900px]" label="New supplier">
        <SupplierForm accounts={accounts} terms={terms} canAddAccount={!isDemoMode()} />
      </Card>
    </>
  );
}

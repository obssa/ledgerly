import { Card, PageTitle } from "@/components/ui";
import { getPaymentTerms, getPriceListChoices } from "@/lib/data";
import { CustomerForm } from "../CustomerForm";

export const metadata = { title: "New customer" };

export default async function NewCustomerPage() {
  const [priceLists, terms] = await Promise.all([getPriceListChoices(), getPaymentTerms(true)]);
  return (
    <>
      <PageTitle title="New customer" />
      <Card className="max-w-[900px]" label="New customer">
        <CustomerForm priceLists={priceLists} terms={terms} />
      </Card>
    </>
  );
}

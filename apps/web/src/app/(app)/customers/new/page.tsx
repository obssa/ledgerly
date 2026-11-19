import { Card, PageTitle } from "@/components/ui";
import { getPriceListChoices } from "@/lib/data";
import { CustomerForm } from "../CustomerForm";

export const metadata = { title: "New customer" };

export default async function NewCustomerPage() {
  const priceLists = await getPriceListChoices();
  return (
    <>
      <PageTitle eyebrow="Money in / Customers" title="New customer" />
      <Card className="max-w-[900px]" label="New customer">
        <CustomerForm priceLists={priceLists} />
      </Card>
    </>
  );
}

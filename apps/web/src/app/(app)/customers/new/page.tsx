import { Card, PageTitle } from "@/components/ui";
import { CustomerForm } from "../CustomerForm";

export const metadata = { title: "New customer" };

export default function NewCustomerPage() {
  return (
    <>
      <PageTitle eyebrow="Money in / Customers" title="New customer" />
      <Card className="max-w-[900px]" label="New customer">
        <CustomerForm />
      </Card>
    </>
  );
}

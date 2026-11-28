import { Card, PageTitle } from "@/components/ui";
import { getLeaseOptions } from "@/lib/data";
import { LeaseForm } from "../LeaseForms";

export const metadata = { title: "Set up a lease" };

export default async function NewLeasePage() {
  const options = await getLeaseOptions();
  return (
    <>
      <PageTitle crumbs={<>New</>} title="Set up a lease" />
      <Card className="max-w-[1000px]" label="Lease"><LeaseForm options={options} /></Card>
    </>
  );
}

import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { NewRequisition } from "../RequisitionForms";

export const metadata = { title: "Ask for something to be bought" };

export default function NewRequisitionPage() {
  return (
    <>
      <PageTitle eyebrow={<Link href="/purchase-requisitions" className="hover:underline">Purchase requisitions</Link>} title="Ask for something to be bought" />
      <Card className="max-w-[900px]" label="New requisition">
        <NewRequisition />
      </Card>
    </>
  );
}

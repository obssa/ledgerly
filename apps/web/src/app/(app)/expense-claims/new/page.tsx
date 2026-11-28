import { redirect } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getExpenseClaims } from "@/lib/data";
import { NewClaimForm } from "../ClaimForms";

export const metadata = { title: "New expense claim" };

/** A new expense claim (083): the receipts of what one paid for the company. */
export default async function NewExpenseClaimPage() {
  const claims = await getExpenseClaims();
  if (!claims) redirect("/no-access");
  return (
    <>
      <PageTitle title="New expense claim" />
      <Card className="max-w-[900px]" label="New expense claim">
        <NewClaimForm accounts={claims.options.accounts} taxRates={claims.options.tax_rates} />
      </Card>
    </>
  );
}

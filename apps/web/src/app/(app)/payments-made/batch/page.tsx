import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getBills, getCompanySettings, getMoneyAccounts } from "@/lib/data";
import { BatchPayForm } from "./BatchPayForm";

export const metadata = { title: "Pay bills in a batch" };

/** Module 3: batch payments. Open bills in the company's currency, paid in one run from one account. */
export default async function BatchPaymentsPage() {
  const settings = await getCompanySettings();
  const currency = settings.base_currency;
  const [bills, accounts] = await Promise.all([getBills(), getMoneyAccounts(currency)]);
  const open = bills.filter((b) => ["posted", "part_paid"].includes(b.status) && b.currency === currency && Number(b.amount_due) > 0)
    .sort((a, b) => (a.due_date ?? "9999").localeCompare(b.due_date ?? "9999"));
  return (
    <>
      <PageTitle eyebrow="Money out / Payments made" title="Pay bills in a batch" actions={<Link href="/payments-made/new" className="btn">Pay one supplier</Link>} />
      <Card className="max-w-[1000px]" label="Pay run">
        <p className="mb-4 text-sm text-muted">
          Tick the bills to pay now, all or part of each. Each supplier gets one payment from the account chosen, with the tax withheld from them
          if you give it. Cheques are written one supplier at a time.
        </p>
        <BatchPayForm bills={open} accounts={accounts} currency={currency} />
      </Card>
    </>
  );
}

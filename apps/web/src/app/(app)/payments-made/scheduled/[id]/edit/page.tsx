import { paymentAccounts } from "@/lib/payments";
import Link from "@/components/Link";
import { notFound, redirect } from "next/navigation";
import { PageTitle } from "@/components/ui";
import { getPayOptions, getScheduledPayment } from "@/lib/data";
import { ScheduleForm } from "../../ScheduleForm";

export const metadata = { title: "Edit scheduled payment" };

/** While it is scheduled: another day, account or method, other bills or amounts. Every change is in the audit log. */
export default async function EditScheduledPaymentPage({ params }: PageProps<"/payments-made/scheduled/[id]/edit">) {
  const { id } = await params;
  const plan = await getScheduledPayment(id);
  if (!plan) notFound();
  if (plan.status !== "scheduled" || !plan.can.pay) redirect(`/payments-made/scheduled/${encodeURIComponent(plan.id)}`);
  const options = await getPayOptions(plan.contact.id);
  const open = options.bills.filter((b) => b.currency === plan.currency);
  // The plan's own bills first, then the supplier's other open bills.
  const planned = new Set(plan.bills.map((b) => b.id));
  const bills = [
    ...plan.bills.map((b) => ({ id: b.id, number: b.number, reference: b.reference, due_date: b.due_date, amount_due: b.amount_due, currency: plan.currency })),
    ...open.filter((b) => !planned.has(b.id)).map((b) => ({ id: b.id, number: b.number, reference: b.reference, due_date: b.due_date, amount_due: b.amount_due, currency: b.currency })),
  ];

  return (
    <>
      <PageTitle crumbs={[<Link key="plan" href={`/payments-made/scheduled/${encodeURIComponent(plan.id)}`} className="hover:underline">{plan.contact.name}</Link>, "Edit"]}
        title="Edit scheduled payment" />
      <ScheduleForm supplier={plan.contact} currency={plan.currency} today={plan.today} bills={bills}
        accounts={paymentAccounts(options.accounts, plan.currency, options.baseCurrency)}
        initial={{ id: plan.id, pay_on: plan.pay_on, account_id: plan.account.id, method: plan.method, reference: plan.reference, note: plan.note,
          bills: Object.fromEntries(plan.bills.map((b) => [b.id, b.amount])) }} />
    </>
  );
}

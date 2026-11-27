import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getConfirmContext, getScheduledPayment } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { dueLabel, METHOD_LABEL } from "@/lib/scheduledPayments";
import { CancelForm, ConfirmForm } from "./ConfirmForms";

export const metadata = { title: "Scheduled payment" };

/**
 * One scheduled payment: to whom, from which account, on which day, for which bills. While scheduled it can be
 * confirmed (the money went out: the payment is recorded), changed or cancelled; once paid it links to its payment.
 */
export default async function ScheduledPaymentPage({ params, searchParams }: PageProps<"/payments-made/scheduled/[id]">) {
  const { id } = await params;
  const { saved } = await searchParams;
  const plan = await getScheduledPayment(id);
  if (!plan) notFound();
  const open = plan.status === "scheduled";
  const context = open && plan.can.pay ? await getConfirmContext(plan.contact.id) : null;
  const near = dueLabel(plan.pay_on, plan.today);

  return (
    <>
      <PageTitle eyebrow={<Link href="/payments-made/scheduled" className="hover:underline">Money out / Scheduled payments</Link>}
        title={<>{plan.contact.name} <span className="num text-muted">{formatMoney(plan.amount, plan.currency)}</span></>}
        actions={open && plan.can.pay ? <Link href={`/payments-made/scheduled/${encodeURIComponent(plan.id)}/edit`} className="btn">Edit</Link> : undefined} />

      {saved === "1" && open && (
        <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
          Scheduled for <Dt v={plan.pay_on} />. You will be reminded by email that day; nothing is in the books until you confirm it.
        </p>
      )}

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="grid gap-4">
          {open && plan.can.pay && context && (
            <ConfirmForm id={plan.id} planned={plan.amount} currency={plan.currency} baseCurrency={context.baseCurrency} method={plan.method}
              today={plan.today} bills={plan.bills} advice={context.advice} />
          )}
          <Card className="!p-2" label="Bills">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Bill</th><th scope="col">Supplier invoice no.</th><th scope="col">Due date</th><th scope="col" className="!text-right">Open balance</th>
                  <th scope="col" className="!text-right">This payment</th></tr></thead>
                <tbody>
                  {plan.bills.length === 0 && <tr><td colSpan={5} className="text-muted">No bills.</td></tr>}
                  {plan.bills.map((b) => (
                    <tr key={b.id}>
                      <td className="num font-semibold"><Link href={`/bills/${encodeURIComponent(b.id)}`} className="text-ink hover:underline">{b.number ?? "—"}</Link></td>
                      <td className="num">{b.reference ?? "—"}</td>
                      <td className="num"><Dt v={b.due_date} /></td>
                      <td className="num text-right">{b.status === "paid" ? <Pill tone="good">Paid</Pill> : formatAmount(b.amount_due)}</td>
                      <td className="num text-right font-semibold">{formatAmount(b.amount)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="font-bold"><td colSpan={4}>Total</td><td className="num text-right">{formatAmount(plan.amount)}</td></tr></tfoot>
              </table>
            </div>
          </Card>
          {open && plan.can.pay && <div><CancelForm id={plan.id} /></div>}
        </div>

        <Card label="Details">
          <dl className="grid gap-3 text-[13.5px]">
            <div><dt className="text-muted">Status</dt><dd className="mt-0.5">
              {plan.status === "paid" ? <Pill tone="good">Paid on <Dt v={plan.closed_on} /></Pill>
                : plan.status === "cancelled" ? <Pill tone="neutral">Cancelled on <Dt v={plan.closed_on} /></Pill>
                : <Pill tone={near.tone}>{near.text}</Pill>}
            </dd></div>
            <div><dt className="text-muted">Pay on</dt><dd className="num font-semibold"><Dt v={plan.pay_on} /></dd></div>
            <div><dt className="text-muted">From</dt><dd>{plan.account.code} {plan.account.name}</dd></div>
            <div><dt className="text-muted">Method</dt><dd>{METHOD_LABEL[plan.method] ?? plan.method}</dd></div>
            {plan.reference && <div><dt className="text-muted">Reference</dt><dd className="num">{plan.reference}</dd></div>}
            {plan.note && <div><dt className="text-muted">Note</dt><dd>{plan.note}</dd></div>}
            <div><dt className="text-muted">Scheduled by</dt><dd>{plan.created_by ?? "—"}</dd></div>
            {plan.payment_id && <div><dt className="text-muted">Payment</dt><dd><Link href={`/payments-made/${encodeURIComponent(plan.payment_id)}`} className="font-semibold text-brand hover:underline">Open the payment</Link></dd></div>}
          </dl>
          {open && !plan.can.pay && <p className="mt-4 text-[13px] text-muted">Only an accountant, owner or admin can confirm or change it.</p>}
        </Card>
      </div>
    </>
  );
}

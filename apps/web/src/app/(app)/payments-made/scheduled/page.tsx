import Link from "@/components/Link";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getScheduledPayments } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { dueLabel, METHOD_LABEL } from "@/lib/scheduledPayments";

export const metadata = { title: "Scheduled payments" };

const VIEWS = [["scheduled", "To pay"], ["paid", "Paid"], ["cancelled", "Cancelled"], ["all", "All"]] as const;

/**
 * Module 3: supplier payments planned for a later day (103), the ones still to pay first, by day. On its day a payment
 * is due: confirm it once the money went out, and only then is it recorded.
 */
export default async function ScheduledPaymentsPage({ searchParams }: PageProps<"/payments-made/scheduled">) {
  const { view: raw } = await searchParams;
  const view = VIEWS.some(([k]) => k === raw) ? (raw as (typeof VIEWS)[number][0]) : "scheduled";
  const list = await getScheduledPayments(view === "all" ? undefined : view);
  const rows = list?.data ?? [];
  const due = list?.totals.filter((t) => t.due_count > 0) ?? [];

  return (
    <>
      <PageTitle eyebrow="Money out" title="Scheduled payments"
        description="Supplier payments planned for a later day. Nothing is in the books until someone confirms the money went out."
        actions={list?.can.pay ? <Link href="/payments-made/scheduled/new" className="btn btn-primary">+ Schedule a payment</Link> : undefined} />

      {due.length > 0 && view === "scheduled" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13.5px] font-semibold text-warn">
          {due.map((t) => `${t.due_count} due (${formatMoney(t.due, t.currency)})`).join(" · ")}: open each one and confirm it once the money has gone out.
        </p>
      )}

      <Segmented label="Show" size="sm" items={VIEWS.map(([k, label]) => ({ key: k, label, href: k === "scheduled" ? "/payments-made/scheduled" : `/payments-made/scheduled?view=${k}`, active: view === k }))} />

      <Card className="!p-2" label="Scheduled payments">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr>
                <th scope="col">Pay on</th><th scope="col">Supplier</th><th scope="col">From</th><th scope="col">Method</th><th scope="col">Bills</th>
                <th scope="col">Status</th><th scope="col" className="!text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {!list && <tr><td colSpan={7} className="text-muted">Not available.</td></tr>}
              {list && rows.length === 0 && (
                <tr><td colSpan={7} className="py-8 text-center text-muted">
                  {view === "scheduled" ? "No payments are scheduled. Plan one from a bill, or with + Schedule a payment." : "None."}
                </td></tr>
              )}
              {rows.map((p) => {
                const near = dueLabel(p.pay_on, list!.today);
                return (
                  <tr key={p.id} className={p.due ? "bg-warn-tint/40" : ""}>
                    <td className="num"><Link href={`/payments-made/scheduled/${encodeURIComponent(p.id)}`} className="font-semibold text-ink hover:underline">{p.pay_on}</Link></td>
                    <td>{p.contact.name}{p.reference && <span className="ml-1.5 text-[12px] text-muted">{p.reference}</span>}</td>
                    <td>{p.account.name}</td>
                    <td>{METHOD_LABEL[p.method] ?? p.method}</td>
                    <td>{p.bill_count}</td>
                    <td>
                      {p.status === "paid" ? <Pill tone="good">Paid {p.closed_on}</Pill>
                        : p.status === "cancelled" ? <Pill>Cancelled</Pill>
                        : <Pill tone={near.tone}>{near.text}</Pill>}
                    </td>
                    <td className="num text-right font-semibold">{formatMoney(p.amount, p.currency)}</td>
                  </tr>
                );
              })}
            </tbody>
            {list && view === "scheduled" && list.totals.length > 0 && (
              <tfoot>
                {list.totals.map((t) => (
                  <tr key={t.currency} className="font-bold"><td colSpan={6}>Scheduled ({t.count})</td><td className="num text-right">{t.currency} {formatAmount(t.scheduled)}</td></tr>
                ))}
              </tfoot>
            )}
          </table>
        </div>
      </Card>
    </>
  );
}

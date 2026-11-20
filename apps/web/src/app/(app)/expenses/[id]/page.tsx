import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getExpense } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { VoidExpense } from "./VoidExpense";
import { AttachmentsPanel } from "../../attachments/AttachmentsPanel";
import { TagsPanel } from "../../tags/TagsPanel";

export const metadata = { title: "Expense" };

/** One expense: who was paid, from which account, the lines and totals. */
export default async function ExpensePage({ params, searchParams }: PageProps<"/expenses/[id]">) {
  const [{ id }, { attachment }] = await Promise.all([params, searchParams]);
  const expense = await getExpense(id);
  if (!expense) notFound();
  const voided = expense.status === "void";

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/expenses" className="hover:underline">Expenses</Link> / Expense</>}
        title={<>Expense <span className="num">{expense.number}</span> {voided ? <Pill>Void</Pill> : <Pill tone="good">Paid</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Expense">
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
            <div><dt className="text-muted">Paid to</dt><dd><Link href={`/suppliers/${encodeURIComponent(expense.contact.id)}`} className="font-bold hover:underline">{expense.contact.name}</Link></dd></div>
            <div><dt className="text-muted">Date</dt><dd className="num">{expense.issue_date}</dd></div>
            <div><dt className="text-muted">Receipt</dt><dd className="num">{expense.reference ?? "—"}</dd></div>
            <div><dt className="text-muted">Paid from</dt><dd>{expense.paid_from ? `${expense.paid_from.code} ${expense.paid_from.name}` : "—"}</dd></div>
            <div><dt className="text-muted">Paid by</dt><dd>{expense.payment_method.replace("_", " ")}</dd></div>
            {Number(expense.fx_rate) !== 1 && <div><dt className="text-muted">Rate</dt><dd className="num">{expense.fx_rate}</dd></div>}
          </dl>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th><th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {(expense.lines ?? []).map((l) => (
                  <tr key={l.line_no}>
                    <td>{l.description}</td>
                    <td className="num text-right">{l.quantity}</td>
                    <td className="num text-right">{formatAmount(l.unit_price)}</td>
                    <td className="num text-right">{formatAmount(l.line_tax)}</td>
                    <td className="num text-right font-semibold">{formatAmount(l.line_subtotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <dl className="ml-auto mt-4 max-w-[320px] text-sm">
            <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(expense.subtotal)}</dd></div>
            <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(expense.tax_total)}</dd></div>
            <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total paid</dt><dd className="num">{formatMoney(expense.total, expense.currency)}</dd></div>
          </dl>
          {expense.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{expense.notes}</p>}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {!voided && expense.can?.void && <VoidExpense id={expense.id} />}
          <AttachmentsPanel kind="expense" documentId={expense.id} flag={typeof attachment === "string" ? attachment : undefined} />
          <TagsPanel kind="expense" id={expense.id} />
        </aside>
      </div>
    </>
  );
}

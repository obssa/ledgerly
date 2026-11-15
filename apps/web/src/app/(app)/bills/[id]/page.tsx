import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBill } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { InvoiceActions } from "../../invoices/[id]/InvoiceActions";

export const metadata = { title: "Bill" };

const STATUS: Record<string, { label: string; tone: "neutral" | "good" | "warn" | "bad" | "brand" }> = {
  draft: { label: "Draft", tone: "neutral" },
  awaiting_approval: { label: "Awaiting approval", tone: "warn" },
  posted: { label: "To pay", tone: "brand" },
  part_paid: { label: "Part paid", tone: "warn" },
  paid: { label: "Paid", tone: "good" },
  void: { label: "Void", tone: "neutral" },
};

/** One bill: the supplier and where they are paid, the lines and totals, possible duplicates, and what can be done next. */
export default async function BillPage({ params, searchParams }: PageProps<"/bills/[id]">) {
  const { id } = await params;
  const { posted } = await searchParams;
  const bill = await getBill(id);
  if (!bill) notFound();
  const status = STATUS[bill.status] ?? { label: bill.status, tone: "neutral" as const };
  const payout = bill.contact.payout ?? {};

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / Bill</>}
        title={<>Bill {bill.number ? <span className="num">{bill.number}</span> : "(draft)"} <Pill tone={status.tone}>{status.label}</Pill></>}
      />
      {posted === "0" && bill.status !== "posted" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          Saved as a draft but not posted. Approve it below to see why, or send it for approval.
        </p>
      )}
      {bill.warnings.map((w) => (
        <p key={`${w.type}-${w.bill_id ?? ""}`} role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          Possible duplicate: {w.message}{" "}
          {w.bill_id && <Link href={`/bills/${encodeURIComponent(w.bill_id)}`} className="underline">Open it</Link>}
        </p>
      ))}

      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Bill">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Supplier</div>
              <Link href={`/suppliers/${encodeURIComponent(bill.contact.id)}`} className="font-bold hover:underline">{bill.contact.name}</Link>
              {bill.contact.tax_id && <div className="num text-[13px] text-muted">TIN {bill.contact.tax_id}</div>}
              <div className="mt-1 text-[13px]">Their invoice <span className="num font-semibold">{bill.reference ?? "—"}</span></div>
            </div>
            <div className="text-right">
              <div><span className="text-muted">Issued</span> <span className="num">{bill.issue_date}</span></div>
              <div><span className="text-muted">Due</span> <span className="num">{bill.due_date ?? "—"}</span></div>
              {Number(bill.fx_rate) !== 1 && <div className="text-muted">Rate <span className="num">{bill.fx_rate}</span></div>}
            </div>
          </div>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th><th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {bill.lines.map((l) => (
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
            <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(bill.subtotal)}</dd></div>
            <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(bill.tax_total)}</dd></div>
            <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total</dt><dd className="num">{formatMoney(bill.total, bill.currency)}</dd></div>
            <div className="flex justify-between py-1 font-semibold"><dt>Still to pay</dt><dd className="num">{formatMoney(bill.amount_due, bill.currency)}</dd></div>
          </dl>
          {bill.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{bill.notes}</p>}
        </Card>

        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {!bill.demo && <InvoiceActions invoice={bill} />}
          <Card label="Pay to">
            <h2 className="text-[17px] font-bold">Pay to</h2>
            {payout.bank_name || payout.mobile_money_number ? (
              <dl className="mt-1.5 text-sm">
                {payout.bank_name && <div><dt className="inline text-muted">Bank </dt><dd className="inline">{payout.bank_name}</dd></div>}
                {payout.account_name && <div><dt className="inline text-muted">Account name </dt><dd className="inline">{payout.account_name}</dd></div>}
                {payout.account_number && <div><dt className="inline text-muted">Account </dt><dd className="num inline">{payout.account_number}</dd></div>}
                {payout.mobile_money_number && <div><dt className="inline text-muted">{payout.mobile_money_provider ?? "Mobile money"} </dt><dd className="num inline">{payout.mobile_money_number}</dd></div>}
              </dl>
            ) : (
              <p className="mt-1.5 text-sm text-muted">No payout details on the supplier yet.</p>
            )}
          </Card>
        </aside>
      </div>
    </>
  );
}

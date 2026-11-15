import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getInvoice } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { InvoiceActions } from "./InvoiceActions";

export const metadata = { title: "Invoice" };

const STATUS: Record<string, { label: string; tone: "neutral" | "good" | "warn" | "bad" | "brand" }> = {
  draft: { label: "Draft", tone: "neutral" },
  awaiting_approval: { label: "Awaiting approval", tone: "warn" },
  posted: { label: "Posted", tone: "brand" },
  part_paid: { label: "Part paid", tone: "warn" },
  paid: { label: "Paid", tone: "good" },
  void: { label: "Void", tone: "neutral" },
};

/** One invoice: lines, totals, e-invoice state, warnings (credit limit) and what can be done next. */
export default async function InvoicePage({ params, searchParams }: PageProps<"/invoices/[id]">) {
  const { id } = await params;
  const { posted } = await searchParams;
  const invoice = await getInvoice(id);
  if (!invoice) notFound();
  const status = STATUS[invoice.status] ?? { label: invoice.status, tone: "neutral" as const };
  const submission = invoice.einvoice_submissions?.[0];

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Invoice</>}
        title={<>{invoice.number ? <span className="num">{invoice.number}</span> : "Draft invoice"} <Pill tone={status.tone}>{status.label}</Pill></>}
      />
      {posted === "0" && invoice.status !== "posted" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          Saved as a draft but not posted. Approve it below to see why, or send it for approval.
        </p>
      )}
      {invoice.warnings.map((w) => (
        <p key={w.type} role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">{w.message}</p>
      ))}

      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Invoice">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Customer</div>
              <Link href={`/customers/${encodeURIComponent(invoice.contact.id)}`} className="font-bold hover:underline">{invoice.contact.name}</Link>
              {invoice.contact.tax_id && <div className="num text-[13px] text-muted">TIN {invoice.contact.tax_id}</div>}
            </div>
            <div className="text-right">
              <div><span className="text-muted">Issued</span> <span className="num">{invoice.issue_date}</span></div>
              <div><span className="text-muted">Due</span> <span className="num">{invoice.due_date ?? "—"}</span></div>
              {Number(invoice.fx_rate) !== 1 && <div className="text-muted">Rate <span className="num">{invoice.fx_rate}</span></div>}
            </div>
          </div>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th><th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {invoice.lines.map((l) => (
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
            <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(invoice.subtotal)}</dd></div>
            <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(invoice.tax_total)}</dd></div>
            <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total</dt><dd className="num">{formatMoney(invoice.total, invoice.currency)}</dd></div>
            <div className="flex justify-between py-1 font-semibold"><dt>Still due</dt><dd className="num">{formatMoney(invoice.amount_due, invoice.currency)}</dd></div>
          </dl>
          {invoice.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{invoice.notes}</p>}
        </Card>

        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {!invoice.demo && <InvoiceActions invoice={invoice} />}
          <Card label="E-invoice">
            <h2 className="text-[17px] font-bold">E-invoice</h2>
            <p className="mt-1.5 text-sm">
              {!submission ? "Not required, or not posted yet." :
                submission.status === "cleared" ? <>Registered · <span className="num">{submission.clearance_number}</span></> :
                submission.status === "rejected" ? <span className="text-bad">Rejected: {submission.last_error}</span> :
                "Queued for the tax authority."}
            </p>
          </Card>
        </aside>
      </div>
    </>
  );
}

import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCompanySettings, getInvoice, getMoneyAccounts, getOpenInvoices } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { RefundPanel } from "../../refunds/RefundPanel";
import { ApplyCredit } from "./ApplyCredit";
import { Reminders } from "./Reminders";
import { RetentionPanel } from "./RetentionPanel";
import { InvoiceActions } from "./InvoiceActions";
import { PrintInvoice, SendInvoice } from "./SendInvoice";
import { AttachmentsPanel } from "../../attachments/AttachmentsPanel";
import { RepeatInvoice } from "./RepeatInvoice";

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
  const { posted, attachment } = await searchParams;
  const [invoice, company] = await Promise.all([getInvoice(id), getCompanySettings()]);
  if (!invoice) notFound();
  const creditNote = invoice.type === "credit_note";
  const status = creditNote && invoice.status === "paid" ? { label: "Applied", tone: "good" as const }
    : creditNote && invoice.status === "part_paid" ? { label: "Credit left", tone: "warn" as const }
    : STATUS[invoice.status] ?? { label: invoice.status, tone: "neutral" as const };
  const submission = invoice.einvoice_submissions?.[0];
  const kind = creditNote ? "Credit note" : "Invoice";
  const canApply = creditNote && invoice.can.apply && ["posted", "part_paid"].includes(invoice.status) && Number(invoice.amount_due) > 0 && !invoice.demo;
  const openInvoices = canApply ? await getOpenInvoices(invoice.contact.id, invoice.currency) : [];
  const canRefund = creditNote && Boolean(invoice.can.refund) && ["posted", "part_paid"].includes(invoice.status) && invoice.amount_due !== "0.00" && !invoice.demo;
  const moneyAccounts = canRefund ? await getMoneyAccounts(invoice.currency) : [];

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / {kind}</>}
        title={<>{kind} {invoice.number ? <span className="num">{invoice.number}</span> : "(draft)"} <span className="print:hidden"><Pill tone={status.tone}>{status.label}</Pill></span></>}
        actions={["posted", "part_paid", "paid"].includes(invoice.status)
          ? <><a href={`/invoices/${encodeURIComponent(invoice.id)}/pdf`} className="btn print:hidden">Download PDF</a><PrintInvoice /></>
          : !creditNote && invoice.can.edit && !invoice.demo && ["draft", "awaiting_approval"].includes(invoice.status)
            ? <Link href={`/invoices/${encodeURIComponent(invoice.id)}/edit`} className="btn print:hidden">Edit draft</Link> : undefined}
      />
      {/* On paper: who it is from (the page's own header and side panels are not printed). */}
      <div className="mb-4 hidden text-[13px] print:block">
        <div className="text-[16px] font-bold">{company.trading_name || company.legal_name}</div>
        {company.trading_name && <div>{company.legal_name}</div>}
        {company.tin && <div>TIN <span className="num">{company.tin}</span>{company.vat_number ? <> · VAT <span className="num">{company.vat_number}</span></> : null}</div>}
        {[company.address_line1, company.address_line2, company.city].filter(Boolean).join(", ") && <div>{[company.address_line1, company.address_line2, company.city].filter(Boolean).join(", ")}</div>}
        {(company.phone || company.email) && <div>{[company.phone, company.email].filter(Boolean).join(" · ")}</div>}
      </div>
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
              {invoice.source && (
                <div className="mt-1 text-[13px]">
                  Made from {invoice.source.type === "quote" ? "quote" : "sales order"}{" "}
                  <Link href={`/${invoice.source.type === "quote" ? "quotes" : "sales-orders"}/${encodeURIComponent(invoice.source.id)}`} className="num font-semibold hover:underline">{invoice.source.number}</Link>
                </div>
              )}
              {creditNote && invoice.original && (
                <div className="mt-1 text-[13px]">Corrects <Link href={`/invoices/${encodeURIComponent(invoice.original.id)}`} className="num font-semibold hover:underline">{invoice.original.number}</Link></div>
              )}
              {creditNote && invoice.restock && <div className="mt-1 text-[13px]">The goods came back into stock</div>}
            </div>
            <div className="text-right">
              <div><span className="text-muted">Issued</span> <span className="num">{invoice.issue_date}</span></div>
              <div><span className="text-muted">Due</span> <span className="num">{invoice.due_date ?? "—"}</span></div>
              {invoice.salesperson && <div><span className="text-muted">Salesperson</span> {invoice.salesperson.name}</div>}
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
            <div className="flex justify-between py-1 font-semibold"><dt>{creditNote ? "Credit left" : "Still due"}</dt><dd className="num">{formatMoney(invoice.amount_due, invoice.currency)}</dd></div>
          </dl>
          {invoice.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{invoice.notes}</p>}
          {/* On paper: how to pay and the footer line, from the company's invoice template. */}
          {!creditNote && company.invoice_template?.payment_details && (
            <div className="mt-4 hidden text-[13px] print:block"><strong>How to pay</strong><p className="whitespace-pre-line">{company.invoice_template.payment_details}</p></div>
          )}
          {company.invoice_template?.footer && <p className="mt-6 hidden text-center text-[12px] text-muted print:block">{company.invoice_template.footer}</p>}
        </Card>

        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4 print:hidden">
          {!invoice.demo && invoice.can.email && ["posted", "part_paid", "paid"].includes(invoice.status) && (
            <SendInvoice invoiceId={invoice.id} email={invoice.contact.email ?? null} label={kind} />
          )}
          {!invoice.demo && <InvoiceActions invoice={invoice} />}
          {!creditNote && invoice.can.credit && ["posted", "part_paid", "paid"].includes(invoice.status) && !invoice.demo && (
            <Link href={`/invoices/${encodeURIComponent(invoice.id)}/credit-note`} className="btn justify-center">Issue a credit note</Link>
          )}
          {!creditNote && !invoice.demo && ["posted", "part_paid"].includes(invoice.status) && Number(invoice.amount_due) > 0 && (
            <Reminders invoiceId={invoice.id} sent={invoice.reminders ?? []} canSend={Boolean(invoice.can.remind)} />
          )}
          {!creditNote && !invoice.demo && ["posted", "part_paid", "paid"].includes(invoice.status) && (invoice.can.retain || (invoice.retentions ?? []).length > 0) && (
            <RetentionPanel invoiceId={invoice.id} retentions={invoice.retentions ?? []} canManage={Boolean(invoice.can.retain)}
              canHold={["posted", "part_paid"].includes(invoice.status) && Number(invoice.amount_due) > 0} />
          )}
          {!creditNote && !invoice.demo && invoice.can.credit && invoice.status !== "void" && (
            <RepeatInvoice invoiceId={invoice.id} canPost={invoice.can.approve} />
          )}
          <AttachmentsPanel kind="invoice" documentId={invoice.id} flag={typeof attachment === "string" ? attachment : undefined} demo={invoice.demo} />
          {canApply && <ApplyCredit creditNoteId={invoice.id} left={invoice.amount_due} invoices={openInvoices} />}
          {canRefund && <RefundPanel side="customer" contactId={invoice.contact.id} currency={invoice.currency} left={invoice.amount_due} creditId={invoice.id} accounts={moneyAccounts} />}
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

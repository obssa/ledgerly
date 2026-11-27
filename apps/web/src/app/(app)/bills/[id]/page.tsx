import { Dt } from "@/components/Dt";
import { DocumentFields } from "@/components/DocumentFields";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBill, getCompanySettings, getCompanyToday, getDocumentHistory, getLandedCosts, getMoneyAccounts, getOpenBills } from "@/lib/data";
import { creditStatus, paymentStatus } from "@/lib/docStatus";
import { formatAmount, formatMoney } from "@/lib/format";
import { ApiError } from "@/lib/api";
import { ApplyCredit } from "../../invoices/[id]/ApplyCredit";
import { RefundPanel } from "../../refunds/RefundPanel";
import { InvoiceActions } from "../../invoices/[id]/InvoiceActions";
import { LandedCostPanel } from "./LandedCostPanel";
import { AttachmentsPanel } from "../../attachments/AttachmentsPanel";
import { TagsPanel } from "../../tags/TagsPanel";
import { FixMistake } from "@/components/FixMistake";
import { DocumentHistoryPanel } from "@/components/DocumentHistoryPanel";

export const metadata = { title: "Bill" };

/** One bill: the supplier and where they are paid, the lines and totals, possible duplicates, and what can be done next. */
export default async function BillPage({ params, searchParams }: PageProps<"/bills/[id]">) {
  const { id } = await params;
  const { posted, attachment, corrected } = await searchParams;
  // The company's settings give the time zone for the history; a role without company.view still sees the bill.
  const [bill, history, settings] = await Promise.all([getBill(id), getDocumentHistory("bill", id),
    getCompanySettings().catch((error: unknown) => { if (error instanceof ApiError) return null; throw error; })]);
  if (!bill) notFound();
  const credit = bill.type === "vendor_credit";
  // In QuickBooks' words: "Overdue 12 days", "Due in 30 days", "Partially paid"; a supplier credit "Unapplied" or "Applied".
  const status = credit ? creditStatus(bill) : paymentStatus(bill, await getCompanyToday());
  const payout = bill.contact.payout ?? {};
  const kind = credit ? "Supplier credit" : "Bill";
  const canApply = credit && Boolean(bill.can.apply) && ["posted", "part_paid"].includes(bill.status) && bill.amount_due !== "0.00" && !bill.demo;
  const openBills = canApply ? await getOpenBills(bill.contact.id, bill.currency) : [];
  const canRefund = credit && Boolean(bill.can.refund) && ["posted", "part_paid"].includes(bill.status) && bill.amount_due !== "0.00" && !bill.demo;
  const moneyAccounts = canRefund ? await getMoneyAccounts(bill.currency) : [];
  const postedBill = !credit && !bill.demo && ["posted", "part_paid", "paid"].includes(bill.status);
  const landed = postedBill ? await getLandedCosts(bill.id) : null;
  const company = postedBill && settings ? settings : null;

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / {kind}</>}
        title={<>{kind} {bill.number ? <span className="num">{bill.number}</span> : "(draft)"} <Pill tone={status.tone}>{status.label}</Pill></>}
        actions={credit || bill.demo ? undefined
          : ["draft", "awaiting_approval"].includes(bill.status) && bill.can.edit ? <Link href={`/bills/${encodeURIComponent(bill.id)}/edit`} className="btn">Edit draft</Link>
          : bill.can.correct ? <Link href={`/bills/${encodeURIComponent(bill.id)}/edit`} className="btn">Edit</Link> : undefined}
      />
      {corrected === "1" && (
        <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
          Changes saved. The history below shows what changed, and the version before.
        </p>
      )}
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

      {bill.match && bill.match.warnings.map((w) => (
        <p key={w} role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Check against the order: {w}</p>
      ))}

      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Bill">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Supplier</div>
              <Link href={`/suppliers/${encodeURIComponent(bill.contact.id)}`} className="font-bold hover:underline">{bill.contact.name}</Link>
              {bill.contact.tax_id && <div className="num text-[13px] text-muted">TIN {bill.contact.tax_id}</div>}
              <div className="mt-1 text-[13px]">{credit ? "Supplier credit no." : "Supplier invoice no."} <span className="num font-semibold">{bill.reference ?? "—"}</span></div>
              {credit && bill.original && (
                <div className="mt-1 text-[13px]">Original bill <Link href={`/bills/${encodeURIComponent(bill.original.id)}`} className="num font-semibold hover:underline">{bill.original.number}</Link></div>
              )}
            </div>
            <div className="text-right">
              <div><span className="text-muted">{credit ? "Supplier credit date" : "Bill date"}</span> <span className="num"><Dt v={bill.issue_date} /></span></div>
              {!credit && bill.payment_terms && <div><span className="text-muted">Terms</span> {bill.payment_terms.name}</div>}
              {bill.tax_mode && bill.tax_mode !== "exclusive" && <div><span className="text-muted">Amounts are</span> {bill.tax_mode === "inclusive" ? "Inclusive of tax" : "Out of scope of tax"}</div>}
              <DocumentFields doc={bill} />
              {!credit && <div><span className="text-muted">Due date</span> <span className="num"><Dt v={bill.due_date} /></span></div>}
              {Number(bill.fx_rate) !== 1 && <div className="text-muted">Exchange rate <span className="num">{bill.fx_rate}</span></div>}
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
            <div className="flex justify-between py-1 font-semibold"><dt>{credit ? "Remaining credit" : "Balance due"}</dt><dd className="num">{formatMoney(bill.amount_due, bill.currency)}</dd></div>
          </dl>
          {bill.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{bill.notes}</p>}
        {bill.match && bill.match.lines.length > 0 && (
          <section className="mt-6 border-t border-line pt-4" aria-label="Three-way match">
            <h2 className="text-[17px] font-bold">Against purchase order{" "}
              <Link href={`/purchase-orders/${encodeURIComponent(bill.match.order_id)}`} className="num hover:underline">{bill.match.order_number ?? "draft"}</Link></h2>
            <p className="mt-1 text-[13px] text-muted">What was ordered, what arrived on goods received notes, and what this bill charges. A difference is a warning, not a block.</p>
            <div className="mt-3 overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Line</th><th scope="col" className="!text-right">Ordered</th><th scope="col" className="!text-right">Received</th>
                    <th scope="col" className="!text-right">Billed before</th><th scope="col" className="!text-right">This bill</th>
                    <th scope="col" className="!text-right">Order price</th><th scope="col" className="!text-right">Bill price</th></tr>
                </thead>
                <tbody>
                  {bill.match.lines.map((m) => (
                    <tr key={m.line_id}>
                      <td>{m.description}</td>
                      <td className="num text-right">{m.ordered}</td>
                      <td className="num text-right">{m.received ?? "—"}</td>
                      <td className="num text-right">{m.billed_elsewhere}</td>
                      <td className={`num text-right ${m.over ? "font-semibold text-bad" : ""}`}>{m.quantity}</td>
                      <td className="num text-right">{formatAmount(m.order_price)}</td>
                      <td className={`num text-right ${m.price_differs ? "font-semibold text-warn" : ""}`}>{formatAmount(m.bill_price)}{m.price_change && m.price_differs ? ` (${m.price_change}%)` : ""}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
        </Card>

        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {!bill.demo && <InvoiceActions invoice={bill} />}
          {!credit && !bill.demo && ["posted", "part_paid"].includes(bill.status) && (<>
            <Link href={`/payments-made/new?supplier=${encodeURIComponent(bill.contact.id)}`} className="btn justify-center">Pay this bill</Link>
            {bill.scheduled_payment ? (
              <Link href={`/payments-made/scheduled/${encodeURIComponent(bill.scheduled_payment.id)}`} className="rounded-lg bg-brand-tint px-3 py-2 text-center text-[13px] font-semibold text-brand hover:underline">
                Payment of {formatAmount(bill.scheduled_payment.amount)} scheduled for <Dt v={bill.scheduled_payment.pay_on} />
              </Link>
            ) : (
              <Link href={`/payments-made/scheduled/new?supplier=${encodeURIComponent(bill.contact.id)}`} className="btn justify-center">Schedule payment</Link>
            )}
          </>)}
          {!credit && !bill.demo && <FixMistake kind="bill" doc={bill} />}
          {landed && company && (landed.spread.length > 0 || landed.received.length > 0 || (landed.can.apply && Number(landed.left) > 0)) && (
            <LandedCostPanel billId={bill.id} landed={landed} base={company.base_currency} />
          )}
          <DocumentHistoryPanel history={history} timeZone={settings?.timezone || "Africa/Addis_Ababa"} auditHref={`/audit?subject_id=${encodeURIComponent(bill.id)}`} />
          <AttachmentsPanel kind="bill" documentId={bill.id} flag={typeof attachment === "string" ? attachment : undefined} demo={bill.demo} />
          <TagsPanel kind="bill" id={bill.id} demo={bill.demo} />
          {canApply && <ApplyCredit creditNoteId={bill.id} left={bill.amount_due} invoices={openBills} bills />}
          {canRefund && <RefundPanel side="supplier" contactId={bill.contact.id} currency={bill.currency} left={bill.amount_due} creditId={bill.id} accounts={moneyAccounts} />}
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

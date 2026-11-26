import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { notFound, redirect } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { QuoteDetail, QuoteKind, QuoteStatus } from "@/lib/api";
import { getInvoiceOptions, getQuote, getQuotes, getReportPeriod } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { InvoiceForm } from "../invoices/new/InvoiceForm";
import { QuoteActions } from "./QuoteActions";

/*
 * Module 2: the screens of quotes and sales orders (056), shared by /quotes and /sales-orders. Both are sales
 * documents that post nothing; a quote becomes a sales order or an invoice, a sales order an invoice.
 */

export const WORDS = {
  quote: { title: "Quotes", one: "Quote", path: "/quotes", newLabel: "+ New quote", until: "Valid until" },
  sales_order: { title: "Sales orders", one: "Sales order", path: "/sales-orders", newLabel: "+ New sales order", until: "Deliver by" },
} as const;

export function statusOf(kind: QuoteKind, status: QuoteStatus): { label: string; tone: "neutral" | "good" | "warn" | "bad" | "brand" } {
  const order = kind === "sales_order";
  return ({
    draft: { label: "Draft", tone: "neutral" },
    sent: { label: "Sent", tone: "brand" },
    accepted: { label: order ? "Confirmed" : "Accepted", tone: "good" },
    declined: { label: "Declined", tone: "bad" },
    expired: { label: "Expired", tone: "warn" },
    converted: { label: order ? "Invoiced" : "Converted", tone: "good" },
    void: { label: "Cancelled", tone: "neutral" },
  } as const)[status] ?? { label: status, tone: "neutral" };
}

const eyebrow = (kind: QuoteKind, last?: string) => (
  <><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / {last ? <><Link href={WORDS[kind].path} className="hover:underline">{WORDS[kind].title}</Link> / {last}</> : WORDS[kind].title}</>
);

export async function QuoteList({ kind, q = {} }: { kind: QuoteKind; q?: Record<string, string | string[] | undefined> }) {
  const period = await getReportPeriod(q, "all");
  const rows = await getQuotes(kind, listQuery(q, period.picked));
  const w = WORDS[kind];

  return (
    <>
      <PageTitle eyebrow={eyebrow(kind)} title={w.title} actions={<>
        {kind === "sales_order" && <Link href="/sales-orders/back-orders" className="btn">Back-orders</Link>}
        <Link href={`${w.path}/new`} className="btn btn-primary">{w.newLabel}</Link>
      </>} />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label={w.title}>
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">
            {kind === "quote" ? "No quotes yet. A quote posts nothing; once accepted it becomes a sales order or an invoice." : "No sales orders yet. Make one here or from an accepted quote."}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Number</th><th scope="col">Customer</th><th scope="col">Issued</th><th scope="col">{w.until}</th><th scope="col">Status</th><th scope="col" className="!text-right">Total</th></tr>
              </thead>
              <tbody>
                {rows.map((q) => {
                  const s = statusOf(kind, q.status);
                  return (
                    <tr key={q.id}>
                      <td className="num font-semibold"><Link href={`${w.path}/${encodeURIComponent(q.id)}`} className="hover:underline">{q.number ?? <span className="text-muted">Draft</span>}</Link></td>
                      <td>{q.contact.name}</td>
                      <td>{q.issue_date}</td>
                      <td>{q.due_date ?? "—"}</td>
                      <td><Pill tone={s.tone}>{s.label}</Pill></td>
                      <td className="num text-right">{formatMoney(q.total, q.currency)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <ListPager path={kind === "quote" ? "/quotes" : "/sales-orders"} picked={period.picked} next={rows.next} prev={rows.prev} />
    </>
  );
}

export async function NewQuote({ kind }: { kind: QuoteKind }) {
  const options = await getInvoiceOptions(kind);
  return (
    <>
      <PageTitle eyebrow={eyebrow(kind, "New")} title={<>New {WORDS[kind].one.toLowerCase()} <Pill>Draft</Pill></>} />
      <InvoiceForm options={options} kind={kind} />
    </>
  );
}

export async function EditQuote({ kind, id }: { kind: QuoteKind; id: string }) {
  const [doc, options] = await Promise.all([getQuote(kind, id), getInvoiceOptions(kind)]);
  if (!doc) notFound();
  const page = `${WORDS[kind].path}/${encodeURIComponent(id)}`;
  if (!doc.can.edit) redirect(page);
  const s = statusOf(kind, doc.status);

  return (
    <>
      <PageTitle eyebrow={eyebrow(kind, "Edit")} title={<>{doc.status === "draft" ? "Edit" : "Revise"} {WORDS[kind].one.toLowerCase()} {doc.number && <span className="num">{doc.number}</span>} <Pill tone={s.tone}>{s.label}</Pill></>} />
      {doc.status !== "draft" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          {kind === "quote" ? "The customer has this quote." : "This order is confirmed."} Saving keeps the earlier version, with what changed, and the number stays.
        </p>
      )}
      <InvoiceForm options={options} kind={kind} draft={doc} />
    </>
  );
}

export async function QuoteView({ kind, id, saved }: { kind: QuoteKind; id: string; saved?: boolean }) {
  const doc = await getQuote(kind, id);
  if (!doc) notFound();
  const w = WORDS[kind];
  const s = statusOf(kind, doc.status);

  return (
    <>
      <PageTitle
        eyebrow={eyebrow(kind, doc.number ?? "Draft")}
        title={<>{w.one} {doc.number ? <span className="num">{doc.number}</span> : "(draft)"} <Pill tone={s.tone}>{s.label}</Pill></>}
        actions={doc.can.edit ? <Link href={`${w.path}/${encodeURIComponent(doc.id)}/edit`} className="btn">{doc.status === "draft" ? "Edit draft" : "Revise"}</Link> : undefined}
      />
      {saved && doc.status === "draft" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          Saved as a draft. {kind === "quote" ? "Mark it as sent below to see why it could not be sent." : "Confirm it below to see why it could not be confirmed."}
        </p>
      )}
      <div className="flex flex-wrap items-start gap-4">
        <QuoteBody doc={doc} />
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {(doc.source || doc.made || (doc.invoices && doc.invoices.length > 0)) && (
            <Card label="Linked documents">
              {doc.source && <p className="text-sm">Made from quote <Link href={`/quotes/${encodeURIComponent(doc.source.id)}`} className="num font-semibold hover:underline">{doc.source.number}</Link></p>}
              {doc.invoices && doc.invoices.length > 0 ? (
                <ul className="text-sm">
                  {doc.invoices.map((i) => (
                    <li key={i.id} className="flex justify-between gap-2 border-t border-line py-1.5 first:border-t-0">
                      <span>Invoice <Link href={`/invoices/${encodeURIComponent(i.id)}`} className="num font-semibold hover:underline">{i.number ?? "draft"}</Link>
                        {i.status === "void" && <span className="ml-1 text-muted">(void)</span>}</span>
                      <span className="num">{formatAmount(i.total)}</span>
                    </li>
                  ))}
                </ul>
              ) : doc.made && (
                <p className="text-sm">
                  {doc.made.type === "invoice" ? "Invoiced as " : "Became sales order "}
                  <Link href={doc.made.type === "invoice" ? `/invoices/${encodeURIComponent(doc.made.id)}` : `/sales-orders/${encodeURIComponent(doc.made.id)}`} className="num font-semibold hover:underline">
                    {doc.made.number ?? "a draft"}
                  </Link>
                </p>
              )}
            </Card>
          )}
          {doc.delivery_notes && doc.delivery_notes.length > 0 && (
            <Card label="Delivery notes">
              <ul className="text-sm">
                {doc.delivery_notes.map((n) => (
                  <li key={n.id} className="flex justify-between gap-2 border-t border-line py-1.5 first:border-t-0">
                    <span><Link href={`/delivery-notes/${encodeURIComponent(n.id)}`} className="num font-semibold hover:underline">{n.number}</Link>
                      {n.status === "void" && <span className="ml-1 text-muted">(void)</span>}</span>
                    <span className="num text-muted">{n.delivered_on}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          <QuoteActions doc={doc} />
          {doc.versions > 0 && <p className="text-[13px] text-muted">Revised {doc.versions} {doc.versions === 1 ? "time" : "times"}; every earlier version is kept.</p>}
        </aside>
      </div>
    </>
  );
}

function QuoteBody({ doc }: { doc: QuoteDetail }) {
  const w = WORDS[doc.type];
  // 067: a sales order shows, line by line, what its invoices delivered and what is left (its back-order).
  const delivery = doc.type === "sales_order" && doc.status !== "draft" ? doc.delivery : null;
  const notes = (doc.delivery_notes ?? []).length > 0;   // 071: what left on delivery notes, line by line
  const left = delivery ? Object.values(delivery).some((d) => Number(d.remaining) > 0) : false;
  return (
    <Card className="min-w-0 flex-[2_1_560px]" label={w.one}>
      <div className="flex flex-wrap justify-between gap-4 text-sm">
        <div>
          <div className="text-[13px] text-muted">Customer</div>
          <Link href={`/customers/${encodeURIComponent(doc.contact.id)}`} className="font-bold hover:underline">{doc.contact.name}</Link>
          {doc.contact.tax_id && <div className="num text-[13px] text-muted">TIN {doc.contact.tax_id}</div>}
        </div>
        <div className="text-right">
          <div><span className="text-muted">Issued</span> <span className="num">{doc.issue_date}</span></div>
          <div><span className="text-muted">{w.until}</span> <span className="num">{doc.due_date ?? "—"}</span></div>
          {doc.salesperson && <div><span className="text-muted">Salesperson</span> {doc.salesperson.name}</div>}
          {Number(doc.fx_rate) !== 1 && <div className="text-muted">Rate <span className="num">{doc.fx_rate}</span></div>}
        </div>
      </div>
      <div className="mt-5 overflow-x-auto">
        <table className="tbl">
          <thead>
            <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th>
              {delivery && <>{notes && <th scope="col" className="!text-right">On notes</th>}<th scope="col" className="!text-right">{notes ? "Invoiced" : "Delivered"}</th><th scope="col" className="!text-right">Left</th></>}
              <th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
          </thead>
          <tbody>
            {doc.lines.map((l) => (
              <tr key={l.line_no}>
                <td>{l.description}</td>
                <td className="num text-right">{l.quantity}</td>
                {delivery && <>
                  {notes && <td className="num text-right">{delivery[l.id ?? ""]?.shipped ?? "—"}</td>}
                  <td className="num text-right">{delivery[l.id ?? ""]?.delivered ?? "—"}</td>
                  <td className={`num text-right ${Number(delivery[l.id ?? ""]?.remaining ?? 0) > 0 ? "font-semibold text-warn" : ""}`}>{delivery[l.id ?? ""]?.remaining ?? "—"}</td>
                </>}
                <td className="num text-right">{formatAmount(l.unit_price)}</td>
                <td className="num text-right">{formatAmount(l.line_tax)}</td>
                <td className="num text-right font-semibold">{formatAmount(l.line_subtotal)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <dl className="ml-auto mt-4 max-w-[320px] text-sm">
        <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(doc.subtotal)}</dd></div>
        <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(doc.tax_total)}</dd></div>
        <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total</dt><dd className="num">{formatMoney(doc.total, doc.currency)}</dd></div>
      </dl>
      {doc.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{doc.notes}</p>}
      {delivery && left && doc.status === "converted" && (
        <p className="mt-4 text-[13px] text-muted">Closed before everything was delivered: what is left is no longer owed to the customer.</p>
      )}
    </Card>
  );
}

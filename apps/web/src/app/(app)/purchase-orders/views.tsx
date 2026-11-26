import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { PurchaseOrderStatus } from "@/lib/api";
import { getBillOptions, getCompanyToday, getPurchaseOrder, getPurchaseOrders, getReportPeriod } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { InvoiceForm } from "../invoices/new/InvoiceForm";
import { PurchaseOrderActions } from "./PurchaseOrderActions";

/* Module 3: the screens of purchase orders (068). An order posts nothing; the bills made from it do. */

export function statusOf(status: PurchaseOrderStatus): { label: string; tone: "neutral" | "good" | "warn" | "bad" | "brand" } {
  return ({
    draft: { label: "Draft", tone: "neutral" },
    accepted: { label: "Issued", tone: "brand" },
    converted: { label: "Billed", tone: "good" },
    void: { label: "Cancelled", tone: "neutral" },
  } as const)[status] ?? { label: status, tone: "neutral" };
}

const eyebrow = (last?: string) => (
  <><Link href="/bills" className="hover:underline">Bills and purchases</Link> / {last ? <><Link href="/purchase-orders" className="hover:underline">Purchase orders</Link> / {last}</> : "Purchase orders"}</>
);

export async function PurchaseOrderList({ q = {} }: { q?: Record<string, string | string[] | undefined> } = {}) {
  const period = await getReportPeriod(q, "all");
  const rows = await getPurchaseOrders(listQuery(q, period.picked));
  return (
    <>
      <PageTitle eyebrow={eyebrow()} title="Purchase orders" actions={<>
        <Link href="/purchase-orders/open" className="btn">Still to come</Link>
        <Link href="/purchase-orders/new" className="btn btn-primary">+ New purchase order</Link>
      </>} />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Purchase orders">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">No purchase orders yet. An order posts nothing; issue it to the supplier, then bill it as their invoices come in.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Number</th><th scope="col">Supplier</th><th scope="col">Issued</th><th scope="col">Expected by</th><th scope="col">Status</th><th scope="col" className="!text-right">Total</th></tr>
              </thead>
              <tbody>
                {rows.map((o) => {
                  const s = statusOf(o.status);
                  return (
                    <tr key={o.id}>
                      <td className="num font-semibold"><Link href={`/purchase-orders/${encodeURIComponent(o.id)}`} className="hover:underline">{o.number ?? <span className="text-muted">Draft</span>}</Link></td>
                      <td>{o.contact.name}</td>
                      <td>{o.issue_date}</td>
                      <td>{o.due_date ?? "—"}</td>
                      <td><Pill tone={s.tone}>{s.label}</Pill></td>
                      <td className="num text-right">{formatMoney(o.total, o.currency)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <ListPager path="/purchase-orders" picked={period.picked} next={rows.next} prev={rows.prev} />
    </>
  );
}

export async function NewPurchaseOrder() {
  const options = await getBillOptions();
  return (
    <>
      <PageTitle eyebrow={eyebrow("New")} title={<>New purchase order <Pill>Draft</Pill></>} />
      <InvoiceForm options={options} kind="purchase_order" />
    </>
  );
}

export async function EditPurchaseOrder({ id }: { id: string }) {
  const [doc, options] = await Promise.all([getPurchaseOrder(id), getBillOptions()]);
  if (!doc) notFound();
  if (!doc.can.edit) redirect(`/purchase-orders/${encodeURIComponent(id)}`);
  const s = statusOf(doc.status);
  return (
    <>
      <PageTitle eyebrow={eyebrow("Edit")} title={<>{doc.status === "draft" ? "Edit" : "Revise"} purchase order {doc.number && <span className="num">{doc.number}</span>} <Pill tone={s.tone}>{s.label}</Pill></>} />
      {doc.status !== "draft" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
          The supplier has this order. Saving keeps the earlier version, with what changed, and the number stays.
        </p>
      )}
      <InvoiceForm options={options} kind="purchase_order" draft={doc} />
    </>
  );
}

export async function PurchaseOrderView({ id, saved }: { id: string; saved?: boolean }) {
  const [doc, today] = await Promise.all([getPurchaseOrder(id), getCompanyToday()]);
  if (!doc) notFound();
  const s = statusOf(doc.status);
  const shown = doc.status !== "draft";
  const receipts = doc.receipts ?? [];
  const byReceipt = receipts.length > 0;
  const left = Object.values(doc.delivery).some((d) => Number(d.remaining) > 0);

  return (
    <>
      <PageTitle
        eyebrow={eyebrow(doc.number ?? "Draft")}
        title={<>Purchase order {doc.number ? <span className="num">{doc.number}</span> : "(draft)"} <Pill tone={s.tone}>{s.label}</Pill></>}
        actions={doc.can.edit ? <Link href={`/purchase-orders/${encodeURIComponent(doc.id)}/edit`} className="btn">{doc.status === "draft" ? "Edit draft" : "Revise"}</Link> : undefined}
      />
      {saved && doc.status === "draft" && (
        <p role="status" className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">Saved as a draft. Issue it below to see why it could not be issued.</p>
      )}
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Purchase order">
          <div className="flex flex-wrap justify-between gap-4 text-sm">
            <div>
              <div className="text-[13px] text-muted">Supplier</div>
              <Link href={`/suppliers/${encodeURIComponent(doc.contact.id)}`} className="font-bold hover:underline">{doc.contact.name}</Link>
              {doc.contact.tax_id && <div className="num text-[13px] text-muted">TIN {doc.contact.tax_id}</div>}
            </div>
            <div className="text-right">
              <div><span className="text-muted">Issued</span> <span className="num">{doc.issue_date}</span></div>
              <div><span className="text-muted">Expected by</span> <span className="num">{doc.due_date ?? "—"}</span></div>
              {Number(doc.fx_rate) !== 1 && <div className="text-muted">Rate <span className="num">{doc.fx_rate}</span></div>}
            </div>
          </div>
          <div className="mt-5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Description</th><th scope="col" className="!text-right">Qty</th>
                  {shown && <>{byReceipt && <th scope="col" className="!text-right">Received</th>}<th scope="col" className="!text-right">Billed</th><th scope="col" className="!text-right">To come</th></>}
                  <th scope="col" className="!text-right">Unit price</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {doc.lines.map((l) => {
                  const d = doc.delivery[l.id ?? ""];
                  return (
                    <tr key={l.line_no}>
                      <td>{l.description}</td>
                      <td className="num text-right">{l.quantity}</td>
                      {shown && <>
                        {byReceipt && <td className="num text-right">{d?.received ?? "—"}</td>}
                        <td className="num text-right">{d?.delivered ?? "—"}</td>
                        <td className={`num text-right ${Number(d?.remaining ?? 0) > 0 ? "font-semibold text-warn" : ""}`}>{d?.remaining ?? "—"}</td>
                      </>}
                      <td className="num text-right">{formatAmount(l.unit_price)}</td>
                      <td className="num text-right">{formatAmount(l.line_tax)}</td>
                      <td className="num text-right font-semibold">{formatAmount(l.line_subtotal)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <dl className="ml-auto mt-4 max-w-[320px] text-sm">
            <div className="flex justify-between py-1"><dt>Subtotal</dt><dd className="num">{formatAmount(doc.subtotal)}</dd></div>
            <div className="flex justify-between py-1"><dt>Tax</dt><dd className="num">{formatAmount(doc.tax_total)}</dd></div>
            <div className="mt-1 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><dt>Total</dt><dd className="num">{formatMoney(doc.total, doc.currency)}</dd></div>
          </dl>
          {doc.notes && <p className="mt-4 whitespace-pre-line text-sm text-muted">{doc.notes}</p>}
          {doc.status === "converted" && left && <p className="mt-4 text-[13px] text-muted">Closed before everything came: what is left is no longer on order.</p>}
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {doc.bills.length > 0 && (
            <Card label="Bills made from it">
              <ul className="text-sm">
                {doc.bills.map((b) => (
                  <li key={b.id} className="flex justify-between gap-2 border-t border-line py-1.5 first:border-t-0">
                    <span>Bill <Link href={`/bills/${encodeURIComponent(b.id)}`} className="num font-semibold hover:underline">{b.number ?? "draft"}</Link>
                      {b.reference && <span className="num ml-1 text-muted">{b.reference}</span>}
                      {b.status === "void" && <span className="ml-1 text-muted">(void)</span>}</span>
                    <span className="num">{formatAmount(b.total)}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          {byReceipt && (
            <Card label="Goods received">
              <ul className="text-sm">
                {receipts.map((r) => (
                  <li key={r.id} className="flex justify-between gap-2 border-t border-line py-1.5 first:border-t-0">
                    <span><Link href={`/goods-received/${encodeURIComponent(r.id)}`} className="num font-semibold hover:underline">{r.number}</Link>
                      <span className="num ml-1 text-muted">{r.received_on}</span>
                      {r.status === "void" && <span className="ml-1 text-muted">(void)</span>}</span>
                    <span className="num">{formatAmount(r.value ?? "0")}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          <PurchaseOrderActions doc={doc} today={today} />
          {doc.versions > 0 && <p className="text-[13px] text-muted">Revised {doc.versions} {doc.versions === 1 ? "time" : "times"}; every earlier version is kept.</p>}
        </aside>
      </div>
    </>
  );
}

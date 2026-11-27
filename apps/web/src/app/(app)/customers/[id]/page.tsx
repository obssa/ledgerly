import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCustomer, getPriceListChoices } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { ArchiveCustomer, CustomerForm } from "../CustomerForm";

export const metadata = { title: "Customer" };

/** One customer: what they owe, their recent posted invoices, and their details. */
export default async function CustomerPage({ params }: PageProps<"/customers/[id]">) {
  const { id } = await params;
  const [customer, priceLists] = await Promise.all([getCustomer(id), getPriceListChoices()]);
  if (!customer) notFound();
  const editable = Boolean(customer.can?.edit) && !customer.demo;
  const over = customer.credit_limit !== null && Number(customer.open_balance) > Number(customer.credit_limit);

  return (
    <>
      <PageTitle
        eyebrow="Money in / Customers"
        title={<>{customer.name} {!customer.is_active && <Pill>Archived</Pill>}</>}
        actions={editable ? <ArchiveCustomer customer={customer} /> : undefined}
      />
      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[1_1_280px]" label="Balance">
          <div className="text-[13px] text-muted">Owes on posted invoices</div>
          <div className="num mt-1 text-[28px] font-extrabold">{formatAmount(customer.open_balance)}</div>
          {customer.credit_limit && (
            <p className={`mt-1 text-sm ${over ? "font-semibold text-warn" : "text-muted"}`}>
              Credit limit {formatAmount(customer.credit_limit)}{over ? ": over the limit" : ""}
            </p>
          )}
          <Link href={`/customers/${encodeURIComponent(customer.id)}/statement`} className="btn mt-4">Statement</Link>
          <h2 className="mb-2 mt-5 text-[15px] font-bold">Recent invoices</h2>
          {(customer.recent_invoices ?? []).length === 0 ? (
            <p className="text-sm text-muted">No posted invoices yet.</p>
          ) : (
            <ul className="text-sm">
              {customer.recent_invoices!.map((inv) => (
                <li key={inv.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                  <span className="num">{inv.number ?? "Draft"} · <Dt v={inv.issue_date} /></span>
                  <span className="num font-semibold">{formatMoney(inv.amount_due, inv.currency)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card className="min-w-0 flex-[2_1_560px]" label="Customer details">
          <CustomerForm customer={customer} readOnly={!editable} priceLists={priceLists} />
          {!editable && !customer.demo && <p className="mt-4 text-sm text-muted">Only an accountant, bookkeeper, owner or admin can change a customer.</p>}
        </Card>
      </div>
    </>
  );
}

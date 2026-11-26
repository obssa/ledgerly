import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCostAccounts, getSupplier } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { ArchiveSupplier, SupplierForm } from "../SupplierForm";

export const metadata = { title: "Supplier" };

/** One supplier: what the company owes them, their recent posted bills, and their details. */
export default async function SupplierPage({ params }: PageProps<"/suppliers/[id]">) {
  const { id } = await params;
  const [supplier, accounts] = await Promise.all([getSupplier(id), getCostAccounts()]);
  if (!supplier) notFound();
  const editable = Boolean(supplier.can?.edit) && !supplier.demo;

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/suppliers" className="hover:underline">Suppliers</Link> / Supplier</>}
        title={<>{supplier.name} {!supplier.is_active && <Pill>Archived</Pill>}</>}
        actions={editable ? <ArchiveSupplier supplier={supplier} /> : undefined}
      />
      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[1_1_280px]" label="Balance">
          <div className="text-[13px] text-muted">We owe on posted bills</div>
          <div className="num mt-1 text-[28px] font-extrabold">{formatAmount(supplier.open_balance)}</div>
          {supplier.withholding_exempt && <p className="mt-1 text-sm text-muted">Exempt from withholding · {supplier.withholding_exempt_reason}</p>}
          <div className="mt-4 flex flex-wrap gap-2">
            <Link href={`/suppliers/${encodeURIComponent(supplier.id)}/statement`} className="btn">Statement</Link>
            {supplier.can?.bill && supplier.is_active && !supplier.demo && <Link href="/bills/new" className="btn">New bill</Link>}
            {supplier.open_balance !== "0.00" && !supplier.open_balance.startsWith("-") && !supplier.demo && <Link href={`/payments-made/new?supplier=${encodeURIComponent(supplier.id)}`} className="btn">Pay</Link>}
          </div>
          <h2 className="mb-2 mt-5 text-[15px] font-bold">Recent bills</h2>
          {(supplier.recent_bills ?? []).length === 0 ? (
            <p className="text-sm text-muted">No posted bills yet.</p>
          ) : (
            <ul className="text-sm">
              {supplier.recent_bills!.map((b) => (
                <li key={b.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                  <Link href={`/bills/${encodeURIComponent(b.id)}`} className="num hover:underline">{b.number ?? "Draft"} · {b.reference ?? b.issue_date}</Link>
                  <span className="num font-semibold">{formatMoney(b.amount_due, b.currency)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card className="min-w-0 flex-[2_1_560px]" label="Supplier details">
          <SupplierForm supplier={supplier} accounts={accounts} readOnly={!editable} />
          {!editable && !supplier.demo && <p className="mt-4 text-sm text-muted">Only an accountant, bookkeeper, owner or admin can change a supplier.</p>}
        </Card>
      </div>
    </>
  );
}

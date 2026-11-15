import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getSupplierStatement } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Supplier statement" };

const KIND: Record<string, string> = { bill: "Bill", payment: "Payment", reversal: "Reversal", opening: "Opening", manual: "Journal" };
const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** What the company owed a supplier before a period, every bill and payment in it, and what it owes at the end. */
export default async function SupplierStatementPage({ params, searchParams }: PageProps<"/suppliers/[id]/statement">) {
  const { id } = await params;
  const q = await searchParams;
  const statement = await getSupplierStatement(id, { from: date(q.from), to: date(q.to) });
  if (!statement) notFound();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href={`/suppliers/${encodeURIComponent(id)}`} className="hover:underline">{statement.supplier.name}</Link> / Statement</>}
        title={<>Statement · <span className="num">{statement.from}</span> to <span className="num">{statement.to}</span></>}
      />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={statement.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={statement.to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <Card className="max-w-[1000px]" label="Statement">
        <div className="mb-4 text-sm">
          <div className="font-bold">{statement.supplier.name}</div>
          {statement.supplier.tax_id && <div className="num text-muted">TIN {statement.supplier.tax_id}</div>}
        </div>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Reference</th><th scope="col" className="!text-right">Billed</th><th scope="col" className="!text-right">Paid</th><th scope="col" className="!text-right">We owe</th></tr>
            </thead>
            <tbody>
              <tr><td className="num">{statement.from}</td><td colSpan={4} className="font-semibold">Balance brought forward</td><td className="num text-right font-semibold">{formatAmount(statement.opening)}</td></tr>
              {statement.lines.map((l, i) => (
                <tr key={`${l.entry}-${i}`}>
                  <td className="num">{l.date}</td>
                  <td>{KIND[l.source_type] ?? l.source_type}</td>
                  <td className="num">
                    {l.document_id ? <Link href={`/bills/${encodeURIComponent(l.document_id)}`} className="hover:underline">{l.reference}</Link> : (l.reference ?? "—")}
                  </td>
                  <td className="num text-right">{l.credit === "0.00" ? "" : formatAmount(l.credit)}</td>
                  <td className="num text-right">{l.debit === "0.00" ? "" : formatAmount(l.debit)}</td>
                  <td className="num text-right">{formatAmount(l.balance)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-ink font-bold"><td colSpan={5}>We owe at {statement.to}</td><td className="num text-right">{formatMoney(statement.closing, statement.currency)}</td></tr>
            </tfoot>
          </table>
        </div>
      </Card>
    </>
  );
}

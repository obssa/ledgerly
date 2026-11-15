import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getCustomerStatement } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Customer statement" };

const KIND: Record<string, string> = { invoice: "Invoice", credit_note: "Credit note", payment: "Payment", reversal: "Reversal", opening: "Opening", manual: "Journal" };
const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** The customer's statement for a period: what they owed before it, every movement, and what they owe at the end. */
export default async function CustomerStatementPage({ params, searchParams }: PageProps<"/customers/[id]/statement">) {
  const { id } = await params;
  const q = await searchParams;
  const statement = await getCustomerStatement(id, { from: date(q.from), to: date(q.to) });
  if (!statement) notFound();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href={`/customers/${encodeURIComponent(id)}`} className="hover:underline">{statement.customer.name}</Link> / Statement</>}
        title={<>Statement · <span className="num">{statement.from}</span> to <span className="num">{statement.to}</span></>}
      />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={statement.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={statement.to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <Card className="max-w-[1000px]" label="Statement">
        <div className="mb-4 text-sm">
          <div className="font-bold">{statement.customer.name}</div>
          {statement.customer.tax_id && <div className="num text-muted">TIN {statement.customer.tax_id}</div>}
        </div>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Reference</th><th scope="col" className="!text-right">Charged</th><th scope="col" className="!text-right">Paid or credited</th><th scope="col" className="!text-right">Balance</th></tr>
            </thead>
            <tbody>
              <tr><td className="num">{statement.from}</td><td colSpan={4} className="font-semibold">Balance brought forward</td><td className="num text-right font-semibold">{formatAmount(statement.opening)}</td></tr>
              {statement.lines.map((l, i) => (
                <tr key={`${l.entry}-${i}`}>
                  <td className="num">{l.date}</td>
                  <td>{KIND[l.source_type] ?? l.source_type}</td>
                  <td className="num">
                    {l.document_id ? <Link href={`/invoices/${encodeURIComponent(l.document_id)}`} className="hover:underline">{l.reference}</Link> : (l.reference ?? "—")}
                  </td>
                  <td className="num text-right">{l.debit === "0.00" ? "" : formatAmount(l.debit)}</td>
                  <td className="num text-right">{l.credit === "0.00" ? "" : formatAmount(l.credit)}</td>
                  <td className="num text-right">{formatAmount(l.balance)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-ink font-bold"><td colSpan={5}>Owes at {statement.to}</td><td className="num text-right">{formatMoney(statement.closing, statement.currency)}</td></tr>
            </tfoot>
          </table>
        </div>
      </Card>
    </>
  );
}

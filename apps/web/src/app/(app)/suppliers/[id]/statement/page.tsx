import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getSupplierStatement, getReportPeriod } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";

export const metadata = { title: "Supplier statement" };

const KIND: Record<string, string> = { bill: "Bill", payment: "Payment", reversal: "Reversal", opening: "Opening", manual: "Journal" };
/** What the company owed a supplier before a period, every bill and payment in it, and what it owes at the end. */
export default async function SupplierStatementPage({ params, searchParams }: PageProps<"/suppliers/[id]/statement">) {
  const { id } = await params;
  const q = await searchParams;
  const period = await getReportPeriod(q, "last_90_days");
  const statement = await getSupplierStatement(id, { from: period.from, to: period.to });
  if (!statement) notFound();

  return (
    <>
      <PageTitle
        eyebrow={<><Link href={`/suppliers/${encodeURIComponent(id)}`} className="hover:underline">{statement.supplier.name}</Link> / Statement</>}
        title={<>Statement · <span className="num">{statement.from}</span> to <span className="num">{statement.to}</span></>}
      />
      <DateRangeFilter picked={period.picked} keep={q} fields={[]}>

      </DateRangeFilter>
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

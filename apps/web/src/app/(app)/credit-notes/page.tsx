import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCreditNotes } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Credit notes" };

/** Credit notes are issued from the invoice they correct (Issue a credit note on a posted invoice). */
export default async function CreditNotesPage() {
  const notes = await getCreditNotes();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Credit notes</>} title="Credit notes" />
      <Card className="!p-2" label="Credit notes">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Number</th><th scope="col">Customer</th><th scope="col">Corrects</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" className="!text-right">Credit</th><th scope="col" className="!text-right">Left</th></tr></thead>
            <tbody>
              {notes.length === 0 && <tr><td colSpan={7} className="text-muted">No credit notes yet. Issue one from a posted invoice.</td></tr>}
              {notes.map((n) => (
                <tr key={n.id}>
                  <td className="num font-semibold"><Link href={`/invoices/${encodeURIComponent(n.id)}`} className="hover:underline">{n.number ?? <span className="text-muted">Draft</span>}</Link></td>
                  <td>{n.contact.name}</td>
                  <td className="num">{n.original?.number ?? "—"}</td>
                  <td className="num">{n.issue_date}</td>
                  <td>{n.status === "draft" ? <Pill>Draft</Pill> : n.status === "paid" ? <Pill tone="good">Applied</Pill> : n.status === "void" ? <Pill>Void</Pill> : <Pill tone="warn">Credit left</Pill>}</td>
                  <td className="num text-right">{formatMoney(n.total, n.currency)}</td>
                  <td className="num text-right font-semibold">{formatMoney(n.amount_due, n.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

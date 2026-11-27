import { Dt } from "@/components/Dt";
import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { creditStatus } from "@/lib/docStatus";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCreditNotes, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Credit notes" };

/** Credit notes are issued from the invoice they correct (Issue a credit note on a posted invoice). */
export default async function CreditNotesPage({ searchParams }: PageProps<"/credit-notes">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const notes = await getCreditNotes(listQuery(q, period.picked));

  return (
    <>
      <PageTitle eyebrow={<><Link href="/invoices" className="hover:underline">Sales and invoices</Link> / Credit notes</>} title="Credit notes" />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Credit notes">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">No.</th><th scope="col">Customer</th><th scope="col">Original invoice</th><th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Open balance</th><th scope="col">Status</th></tr></thead>
            <tbody>
              {notes.length === 0 && <tr><td colSpan={7} className="text-muted">No credit notes yet. Issue one from a posted invoice.</td></tr>}
              {notes.map((n) => {
                const s = creditStatus(n);
                return (
                  <tr key={n.id}>
                    <td className="num"><Dt v={n.issue_date} /></td>
                    <td className="num font-semibold"><Link href={`/invoices/${encodeURIComponent(n.id)}`} className="hover:underline">{n.number ?? <span className="text-muted">Draft</span>}</Link></td>
                    <td>{n.contact.name}</td>
                    <td className="num">{n.original?.number ?? "—"}</td>
                    <td className="num text-right">{formatMoney(n.total, n.currency)}</td>
                    <td className="num text-right font-semibold">{formatMoney(n.status === "void" ? "0.00" : n.amount_due, n.currency)}</td>
                    <td><Pill tone={s.tone}>{s.label}</Pill></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/credit-notes" picked={period.picked} next={notes.next} prev={notes.prev} />
    </>
  );
}

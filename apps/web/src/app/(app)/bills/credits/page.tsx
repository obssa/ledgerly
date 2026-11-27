import { ListPager, ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "@/components/Link";
import { creditStatus } from "@/lib/docStatus";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getSupplierCredits, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Supplier credits" };

/** Supplier credits are recorded from the bill they correct (Record a supplier credit on a posted bill). */
export default async function SupplierCreditsPage({ searchParams }: PageProps<"/bills/credits">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const credits = await getSupplierCredits(listQuery(q, period.picked));

  return (
    <>
      <PageTitle eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / Supplier credits</>} title="Supplier credits" />
      <ListPeriod picked={period.picked} />
      <Card className="!p-2" label="Supplier credits">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Date</th><th scope="col">No.</th><th scope="col">Supplier</th><th scope="col">Supplier credit no.</th><th scope="col">Original bill</th><th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Open balance</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
              {credits.length === 0 && <tr><td colSpan={8} className="text-muted">No supplier credits yet. Record one from a posted bill.</td></tr>}
              {credits.map((c) => {
                const s = creditStatus(c);
                return (
                  <tr key={c.id}>
                    <td className="num">{c.issue_date}</td>
                    <td className="num font-semibold"><Link href={`/bills/${encodeURIComponent(c.id)}`} className="hover:underline">{c.number ?? <span className="text-muted">Draft</span>}</Link></td>
                    <td>{c.contact.name}</td>
                    <td className="num">{c.reference ?? "—"}</td>
                    <td className="num">{c.original?.number ?? "—"}</td>
                    <td className="num text-right">{formatMoney(c.total, c.currency)}</td>
                    <td className="num text-right font-semibold">{formatMoney(c.status === "void" ? "0.00" : c.amount_due, c.currency)}</td>
                    <td><Pill tone={s.tone}>{s.label}</Pill></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
      <ListPager path="/bills/credits" picked={period.picked} next={credits.next} prev={credits.prev} />
    </>
  );
}

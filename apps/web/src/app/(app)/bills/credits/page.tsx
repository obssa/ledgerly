import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getSupplierCredits } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Supplier credits" };

/** Supplier credits are recorded from the bill they correct (Record a supplier credit on a posted bill). */
export default async function SupplierCreditsPage() {
  const credits = await getSupplierCredits();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/bills" className="hover:underline">Bills and purchases</Link> / Supplier credits</>} title="Supplier credits" />
      <Card className="!p-2" label="Supplier credits">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead>
              <tr><th scope="col">Number</th><th scope="col">Supplier</th><th scope="col">Their reference</th><th scope="col">Corrects</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" className="!text-right">Credit</th><th scope="col" className="!text-right">Left</th></tr>
            </thead>
            <tbody>
              {credits.length === 0 && <tr><td colSpan={8} className="text-muted">No supplier credits yet. Record one from a posted bill.</td></tr>}
              {credits.map((c) => (
                <tr key={c.id}>
                  <td className="num font-semibold"><Link href={`/bills/${encodeURIComponent(c.id)}`} className="hover:underline">{c.number ?? <span className="text-muted">Draft</span>}</Link></td>
                  <td>{c.contact.name}</td>
                  <td className="num">{c.reference ?? "—"}</td>
                  <td className="num">{c.original?.number ?? "—"}</td>
                  <td className="num">{c.issue_date}</td>
                  <td>
                    {c.status === "draft" ? <Pill>Draft</Pill> : c.status === "awaiting_approval" ? <Pill tone="warn">Awaiting approval</Pill>
                      : c.status === "paid" ? <Pill tone="good">Applied</Pill> : c.status === "void" ? <Pill>Void</Pill> : <Pill tone="warn">Credit left</Pill>}
                  </td>
                  <td className="num text-right">{formatMoney(c.total, c.currency)}</td>
                  <td className="num text-right font-semibold">{formatMoney(c.amount_due, c.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}

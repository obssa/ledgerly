import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getLeases } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Leases" };

/** Module 9 (095): the leases the company holds, what is still owed on each, and the payments to come by when they fall due. */
export default async function LeasesPage() {
  const r = await getLeases();
  return (
    <>
      <PageTitle eyebrow={<><Link href="/fixed-assets" className="hover:underline">Fixed assets</Link> / Leases</>} title="Leases"
        actions={r?.can.manage ? <Link href="/leases/new" className="btn btn-primary">Set up a lease</Link> : undefined} />
      {!r ? <p className="text-[14px] text-muted">Not available in the demo or for your role.</p> : (
        <>
          <div className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
            <Card><div className="text-[13px] text-muted">Due within a year</div><div className="num mt-1.5 text-[22px] font-semibold">{formatAmount(r.to_pay.within_year)}</div></Card>
            <Card><div className="text-[13px] text-muted">In one to five years</div><div className="num mt-1.5 text-[22px] font-semibold">{formatAmount(r.to_pay.one_to_five)}</div></Card>
            <Card><div className="text-[13px] text-muted">Later</div><div className="num mt-1.5 text-[22px] font-semibold">{formatAmount(r.to_pay.later)}</div></Card>
          </div>
          <Card className="!p-2" label="Leases">
            {r.data.length === 0 ? <p className="p-3 text-sm text-muted">No leases yet.</p> : (
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead><tr><th scope="col">Lease</th><th scope="col">Kind</th><th scope="col">Lessor</th><th scope="col" className="!text-right">Monthly</th>
                    <th scope="col" className="!text-right">Paid</th><th scope="col" className="!text-right">Owed (finance)</th><th scope="col" className="!text-right">To pay</th></tr></thead>
                  <tbody>
                    {r.data.map((l) => (
                      <tr key={l.id} className={l.status === "ended" ? "text-muted" : ""}>
                        <td><Link href={`/leases/${encodeURIComponent(l.id)}`} className="font-semibold hover:underline"><span className="num">{l.code}</span> {l.name}</Link>
                          {l.status === "ended" && <span className="ml-2"><Pill>Ended</Pill></span>}</td>
                        <td>{l.kind === "finance" ? "Finance" : "Operating"}</td>
                        <td>{l.lessor?.name ?? "—"}</td>
                        <td className="num text-right">{formatAmount(l.payment)}</td>
                        <td className="num text-right">{l.paid} of {l.months}</td>
                        <td className="num text-right">{l.owed === null ? "—" : formatAmount(l.owed)}</td>
                        <td className="num text-right">{formatAmount((Number(l.to_pay.within_year) + Number(l.to_pay.one_to_five) + Number(l.to_pay.later)).toFixed(2))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
          <p className="mt-3 text-[13px] text-muted">To pay is the payments still to make on active leases, as at {r.as_of}, by when each falls due.</p>
        </>
      )}
    </>
  );
}

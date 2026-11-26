import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getLease, getLeaseOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { EndLease, PayLease } from "../LeaseForms";

export const metadata = { title: "Lease" };

/** Module 9 (095): one lease, its schedule of payments (made and to come) and paying the next one. */
export default async function LeasePage({ params }: PageProps<"/leases/[id]">) {
  const { id } = await params;
  const [l, options] = await Promise.all([getLease(id), getLeaseOptions()]);
  if (!l) notFound();
  const next = l.schedule.find((p) => !p.paid_on);
  const finance = l.kind === "finance";

  return (
    <>
      <PageTitle eyebrow={<><Link href="/leases" className="hover:underline">Leases</Link> / <span className="num">{l.code}</span></>}
        title={<>{l.name} {l.status === "ended" && <Pill>Ended</Pill>}</>} />
      <Card className="mb-4" label="Lease">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Kind</dt><dd>{finance ? "Finance lease" : "Operating lease"}</dd></div>
          <div><dt className="text-muted">Lessor</dt><dd>{l.lessor?.name ?? "—"}</dd></div>
          <div><dt className="text-muted">Payments</dt><dd className="num">{l.months} × {formatAmount(l.payment)}, {l.in_advance ? "in advance" : "in arrears"}, from {l.starts_on}</dd></div>
          {finance && <div><dt className="text-muted">Rate</dt><dd className="num">{(Number(l.annual_rate) * 100).toFixed(2)}% a year</dd></div>}
          {finance && <div><dt className="text-muted">Recognised at</dt><dd className="num font-semibold">
            {l.commencement_entry_id ? <Link href={`/journal/${encodeURIComponent(l.commencement_entry_id)}`} className="hover:underline">{formatAmount(l.present_value ?? "0")}</Link> : formatAmount(l.present_value ?? "0")}</dd></div>}
          {finance && l.fixed_asset_id && <div><dt className="text-muted">The asset</dt><dd><Link href={`/fixed-assets/${encodeURIComponent(l.fixed_asset_id)}`} className="text-brand hover:underline">On the register</Link></dd></div>}
        </dl>
      </Card>
      {l.status === "active" && next && l.can.pay && (
        <Card className="mb-4" label="Pay">
          <h2 className="mb-2 text-[15px] font-bold">Payment {next.number} of {l.months}</h2>
          <PayLease id={l.id} banks={options.banks}
            next={finance ? `${formatAmount(next.amount)}: ${formatAmount(next.interest)} interest, ${formatAmount(next.principal)} off the liability. Due ${next.due_on}.`
              : `${formatAmount(next.amount)} to the expense account. Due ${next.due_on}.`} />
        </Card>
      )}
      <Card className="mb-4 !p-2" label="Schedule">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">#</th><th scope="col">Due</th><th scope="col" className="!text-right">Payment</th>
              {finance && <><th scope="col" className="!text-right">Interest</th><th scope="col" className="!text-right">Repayment</th><th scope="col" className="!text-right">Owed after</th></>}
              <th scope="col">Paid</th></tr></thead>
            <tbody>
              {l.schedule.map((p) => (
                <tr key={p.number} className={p.paid_on ? "" : "text-muted"}>
                  <td className="num">{p.number}</td>
                  <td className="num">{p.due_on}</td>
                  <td className="num text-right">{formatAmount(p.amount)}</td>
                  {finance && <><td className="num text-right">{formatAmount(p.interest)}</td><td className="num text-right">{formatAmount(p.principal)}</td><td className="num text-right">{formatAmount(p.owed)}</td></>}
                  <td className="num">{p.paid_on && p.entry_id ? <Link href={`/journal/${encodeURIComponent(p.entry_id)}`} className="hover:underline">{p.paid_on}</Link> : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {l.status === "active" && l.can.manage && <EndLease id={l.id} />}
    </>
  );
}

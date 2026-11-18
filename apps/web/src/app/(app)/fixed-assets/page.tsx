import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getFixedAssets } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { RunDepreciation } from "./AssetForms";

export const metadata = { title: "Fixed assets" };

const monthEnd = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** Module 9c: the fixed asset register, the monthly depreciation run and the runs posted. */
export default async function FixedAssetsPage({ searchParams }: PageProps<"/fixed-assets">) {
  const q = await searchParams;
  const fa = await getFixedAssets(monthEnd(q.period_end));

  return (
    <>
      <PageTitle eyebrow="Books" title="Fixed assets" actions={<><Link href="/fixed-assets/tax" className="btn">Tax depreciation</Link>{fa?.register.can.manage && <Link href="/fixed-assets/new" className="btn btn-primary">Register an asset</Link>}</>} />
      {!fa ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
        <>
          {fa.register.can.depreciate && fa.preview && (
            <Card className="mb-4" label="Depreciation">
              <h2 className="mb-2 text-[15px] font-bold">Depreciation for the month to <span className="num">{fa.preview.period_end}</span></h2>
              {fa.preview.lines.length === 0 ? <p className="text-[14px] text-muted">Nothing to depreciate for that month.</p> : (
                <>
                  <ul className="mb-3 grid gap-1 text-[14px]">
                    {fa.preview.lines.map((l) => <li key={l.asset_id} className="flex justify-between gap-4 border-b border-line py-1"><span><span className="num">{l.code}</span> {l.name}</span><span className="num">{formatAmount(l.amount)}</span></li>)}
                  </ul>
                  <RunDepreciation periodEnd={fa.preview.period_end} total={formatAmount(fa.preview.total)} />
                </>
              )}
              <p className="mt-3 text-[13px] text-muted">Runs go forward one month at a time. Each posts one entry: Dr depreciation expense, Cr accumulated depreciation.</p>
            </Card>
          )}
          <Card className="mb-4 !p-2" label="Register">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Asset</th><th scope="col">Category</th><th scope="col">Location</th><th scope="col">Acquired</th>
                    <th scope="col" className="!text-right">Cost</th><th scope="col" className="!text-right">Depreciated</th><th scope="col" className="!text-right">Book value</th></tr>
                </thead>
                <tbody>
                  {fa.register.data.length === 0 && <tr><td colSpan={7} className="text-muted">No assets registered yet.</td></tr>}
                  {fa.register.data.map((a) => (
                    <tr key={a.id}>
                      <td><Link href={`/fixed-assets/${encodeURIComponent(a.id)}`} className="font-semibold hover:underline"><span className="num">{a.code}</span> {a.name}</Link>
                        {a.status === "disposed" && <span className="ml-2"><Pill>Disposed</Pill></span>}</td>
                      <td>{a.category ?? "—"}</td><td>{a.location?.name ?? "—"}</td><td className="num">{a.acquired_on}</td>
                      <td className="num text-right">{formatAmount(a.cost)}</td><td className="num text-right">{formatAmount(a.depreciated)}</td>
                      <td className="num text-right font-semibold">{formatAmount(a.book_value)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
          <Card className="!p-2" label="Runs">
            <h2 className="px-3 pt-2 text-[15px] font-bold">Depreciation posted</h2>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Month to</th><th scope="col">Entry</th><th scope="col" className="!text-right">Total</th></tr></thead>
                <tbody>
                  {fa.register.runs.length === 0 && <tr><td colSpan={3} className="text-muted">None yet.</td></tr>}
                  {fa.register.runs.map((r) => (
                    <tr key={r.id}><td className="num">{r.period_end}</td><td><Link href={`/journal/${encodeURIComponent(r.entry.id)}`} className="num hover:underline">#{r.entry.number}</Link></td><td className="num text-right">{formatAmount(r.total)}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </>
  );
}

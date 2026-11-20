import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getAssetOptions, getBankAccounts, getFixedAsset } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { AssetForm, DisposeAsset, ImpairAsset, RevalueAsset } from "../AssetForms";

export const metadata = { title: "Fixed asset" };

/** Module 9c: one asset, its depreciation so far and the months ahead, and editing it. */
export default async function FixedAssetPage({ params }: PageProps<"/fixed-assets/[id]">) {
  const { id } = await params;
  const [a, options, banks] = await Promise.all([getFixedAsset(id), getAssetOptions(), getBankAccounts()]);
  if (!a) notFound();
  const how = a.method === "straight_line" ? `Straight line over ${a.useful_life_months} months` : `Reducing balance at ${Number(a.annual_rate) * 100}% a year`;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/fixed-assets" className="hover:underline">Fixed assets</Link> / <span className="num">{a.code}</span></>}
        title={<>{a.name} {a.status === "disposed" && <Pill>Disposed</Pill>}</>} />
      <Card className="mb-4" label="Asset">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Cost</dt><dd className="num text-[18px] font-bold">{formatAmount(a.cost)}</dd></div>
          <div><dt className="text-muted">Depreciated</dt><dd className="num text-[18px] font-bold">{formatAmount(a.depreciated)}</dd></div>
          <div><dt className="text-muted">Book value</dt><dd className="num text-[18px] font-bold">{formatAmount(a.book_value)}</dd></div>
          <div><dt className="text-muted">Residual value</dt><dd className="num">{formatAmount(a.residual_value)}</dd></div>
          <div><dt className="text-muted">Depreciation</dt><dd>{how}, from <span className="num">{a.depreciation_starts_on}</span></dd></div>
          <div><dt className="text-muted">Kept on</dt><dd><span className="num">{a.asset_account.code}</span> {a.asset_account.name}</dd></div>
          <div><dt className="text-muted">Where, and who keeps it</dt><dd>{a.location?.name ?? "—"}{a.custodian ? `, ${a.custodian}` : ""}</dd></div>
          {a.disposal && <div><dt className="text-muted">Disposed of</dt><dd><Link href={`/journal/${encodeURIComponent(a.disposal.entry_id)}`} className="num hover:underline">{a.disposal.date}</Link>
            {a.disposal.proceeds !== "0.00" ? <>, sold for <span className="num">{formatAmount(a.disposal.proceeds)}</span></> : ", scrapped"}</dd></div>}
        </dl>
      </Card>
      <Card className="mb-4 !p-2" label="Schedule">
        <div className="grid gap-4 overflow-x-auto md:grid-cols-2">
          <table className="tbl">
            <caption className="px-3 pt-2 text-left text-[14px] font-bold">Posted</caption>
            <thead><tr><th scope="col">Month to</th><th scope="col" className="!text-right">Charge</th></tr></thead>
            <tbody>
              {a.opening_depreciation !== "0.00" && <tr><td>Before Ledgerly</td><td className="num text-right">{formatAmount(a.opening_depreciation)}</td></tr>}
              {a.posted.length === 0 && a.opening_depreciation === "0.00" && <tr><td colSpan={2} className="text-muted">Nothing yet.</td></tr>}
              {a.posted.map((p) => <tr key={p.period_end}><td className="num"><Link href={`/journal/${encodeURIComponent(p.entry_id)}`} className="hover:underline">{p.period_end}</Link></td><td className="num text-right">{formatAmount(p.amount)}</td></tr>)}
            </tbody>
          </table>
          <table className="tbl">
            <caption className="px-3 pt-2 text-left text-[14px] font-bold">The next months</caption>
            <thead><tr><th scope="col">Month to</th><th scope="col" className="!text-right">Charge</th></tr></thead>
            <tbody>
              {a.ahead.length === 0 && <tr><td colSpan={2} className="text-muted">Fully depreciated.</td></tr>}
              {a.ahead.map((p) => <tr key={p.period_end}><td className="num">{p.period_end}</td><td className="num text-right">{formatAmount(p.amount)}</td></tr>)}
            </tbody>
          </table>
        </div>
      </Card>
      {(a.impairments ?? []).length > 0 && (
        <Card className="mb-4" label="Impairments">
          <h2 className="mb-2 text-[15px] font-bold">Written down</h2>
          <ul className="text-sm">
            {a.impairments!.map((i) => (
              <li key={i.id} className="flex justify-between gap-2 border-t border-line py-1.5">
                <span><Link href={`/journal/${encodeURIComponent(i.entry_id)}`} className="num hover:underline">{i.impaired_on}</Link> · {i.reason}</span>
                <span className="num">{formatAmount(i.amount)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {(a.revaluations ?? []).length > 0 && (
        <Card className="mb-4" label="Revaluations">
          <h2 className="mb-2 text-[15px] font-bold">Revalued</h2>
          <ul className="text-sm">
            {a.revaluations!.map((r) => (
              <li key={r.id} className="flex flex-wrap justify-between gap-2 border-t border-line py-1.5">
                <span><Link href={`/journal/${encodeURIComponent(r.entry_id)}`} className="num hover:underline">{r.revalued_on}</Link> · {r.reason}</span>
                <span className="num">{formatAmount(r.carrying_before)} → {formatAmount(r.fair_value)}
                  <span className="ml-2 text-[12px] text-muted">surplus {formatAmount(r.to_surplus)} · profit or loss {formatAmount(r.to_profit)}</span></span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-[13px] text-muted">Surplus on this asset: <span className="num">{formatAmount(a.surplus ?? "0")}</span></p>
        </Card>
      )}
      {a.can.manage && a.status === "active" && (
        <Card className="mb-4" label="Revalue">
          <h2 className="mb-3 text-[15px] font-bold">Revalue to fair value</h2>
          <RevalueAsset id={a.id} bookValue={formatAmount(a.book_value)} accounts={a.surplus_accounts ?? []}
            kept={a.revaluations?.[0]?.surplus_account_id} />
        </Card>
      )}
      {a.can.manage && a.status === "active" && (
        <Card className="mb-4" label="Write down">
          <h2 className="mb-3 text-[15px] font-bold">Write it down (impairment)</h2>
          <ImpairAsset id={a.id} bookValue={formatAmount(a.book_value)} />
        </Card>
      )}
      {a.can.manage && a.status === "active" && (
        <Card className="mb-4" label="Dispose">
          <h2 className="mb-3 text-[15px] font-bold">Sell or scrap it</h2>
          <DisposeAsset id={a.id} bookValue={formatAmount(a.book_value)} banks={banks.data.filter((b) => b.is_active && b.kind !== "undeposited_funds")} />
        </Card>
      )}
      {a.can.manage && options && a.status === "active" && (
        <Card label="Edit">
          <h2 className="mb-3 text-[15px] font-bold">Edit</h2>
          <AssetForm asset={a} options={options} locked={a.depreciated_by_runs} />
        </Card>
      )}
    </>
  );
}

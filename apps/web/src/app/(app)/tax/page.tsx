import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getTaxCentre } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { FileReturn } from "./TaxForms";
import { Worksheet } from "./Worksheet";

export const metadata = { title: "Tax centre" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);
const KINDS = [["vat", "VAT"], ["withholding", "Withholding tax"]] as const;

/** Module 7: the VAT and withholding worksheets of any period, built from the ledger, filing a return and the returns filed. */
export default async function TaxCentrePage({ searchParams }: PageProps<"/tax">) {
  const q = await searchParams;
  const kind = q.kind === "withholding" ? "withholding" : "vat";
  const centre = await getTaxCentre({ kind, from: date(q.from), to: date(q.to) });

  return (
    <>
      <PageTitle eyebrow="Compliance" title="Tax centre" />
      {!centre ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
        <>
          <form className="mb-4 flex flex-wrap items-end gap-2.5">
            <div>
              <label className="field-label" htmlFor="kind">Return</label>
              <select className="field" id="kind" name="kind" defaultValue={kind}>{KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            </div>
            <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={centre.sheet.from} /></div>
            <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={centre.sheet.to} /></div>
            <button type="submit" className="btn">Show</button>
          </form>
          <Worksheet sheet={centre.sheet} />
          {(centre.sheet.filed ?? []).length > 0 ? (
            <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
              Filed: {(centre.sheet.filed ?? []).map((f) => (
                <Link key={f.id} href={`/tax/returns/${encodeURIComponent(f.id)}`} className="mr-2 underline">{f.period_start} to {f.period_end} (on {f.filed_on})</Link>
              ))}
            </p>
          ) : centre.sheet.can?.file ? (
            <Card className="mb-4 max-w-[900px]" label="File the return">
              <h2 className="mb-3 text-[15px] font-bold">Record the return as filed</h2>
              <FileReturn kind={centre.sheet.kind} from={centre.sheet.from} to={centre.sheet.to} />
            </Card>
          ) : null}
          <Card className="!p-2" label="Returns filed">
            <h2 className="px-3 pt-2 text-[15px] font-bold">Returns filed</h2>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Return</th><th scope="col">Period</th><th scope="col">Filed</th><th scope="col">Reference</th><th scope="col" className="!text-right">Net</th><th scope="col">Paid</th></tr></thead>
                <tbody>
                  {centre.returns.length === 0 && <tr><td colSpan={6} className="text-muted">None yet.</td></tr>}
                  {centre.returns.map((r) => (
                    <tr key={r.id}>
                      <td><Link href={`/tax/returns/${encodeURIComponent(r.id)}`} className="font-semibold hover:underline">{r.kind === "vat" ? "VAT" : "Withholding"}</Link></td>
                      <td className="num">{r.period_start} to {r.period_end}</td><td className="num">{r.filed_on}</td><td>{r.reference ?? ""}</td>
                      <td className="num text-right">{formatAmount(r.net)}</td>
                      <td>{r.settled_on ? <Pill tone="good">{r.settled_on}</Pill> : <Pill tone="warn">Not yet</Pill>}</td>
                    </tr>
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

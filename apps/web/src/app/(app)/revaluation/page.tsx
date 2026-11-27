import { parseDate, type DatePrefs } from "@/lib/dates";
import { DateInput } from "@/components/DateInput";
import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getRevaluationPreview, getRevaluations, getDatePrefs } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { drCr } from "@/lib/ledger";
import { PostRevaluation } from "./PostRevaluation";

export const metadata = { title: "Currency revaluation" };

/** A date from the form: as stored, or typed the company's way (it works without JavaScript). */
const date = (v: unknown, prefs: DatePrefs) => (typeof v === "string" ? parseDate(v, prefs) ?? undefined : undefined);
const KIND: Record<string, string> = { account: "Bank or wallet", invoice: "Invoice", credit_note: "Credit note", bill: "Bill", vendor_credit: "Supplier credit",
  customer_credit: "Customer credit not yet applied", supplier_advance: "Advance to a supplier not yet applied" };

function Amount({ value }: { value: string }) {
  const b = drCr(value);
  return <>{formatAmount(b.amount)}{b.side && <span className="ml-1 text-[12px] text-muted">{b.side}</span>}</>;
}

/** Module 6: restate foreign-currency balances at a month end (IAS 21), reversed the next day; past runs with their reports. */
export default async function RevaluationPage({ searchParams }: PageProps<"/revaluation">) {
  const q = await searchParams;
  const asOf = date(q.as_of, await getDatePrefs());
  const given: Record<string, string> = {};
  for (const [k, v] of Object.entries(q)) {
    const m = /^rate_([A-Z]{3})$/.exec(k);
    if (m && typeof v === "string" && /^\d+(\.\d{1,6})?$/.test(v.trim())) given[m[1]] = v.trim();
  }
  const [runs, preview] = await Promise.all([getRevaluations(), asOf ? getRevaluationPreview(asOf, given) : Promise.resolve(null)]);
  const lastMonthEnd = (() => { const d = new Date(); d.setDate(0); return d.toISOString().slice(0, 10); })();
  const currencies = [...new Set([...Object.keys(preview?.preview?.rates ?? {}), ...(preview?.missing ?? []), ...Object.keys(given)])];

  return (
    <>
      <PageTitle eyebrow="Books" title="Currency revaluation" />
      {!runs ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <>
          {runs.can.revalue && (
            <Card className="mb-4" label="Revalue">
              <h2 className="mb-1 text-[15px] font-bold">Revalue foreign balances</h2>
              <p className="mb-3 text-[13px] text-muted">Foreign-currency bank and wallet balances and open foreign-currency invoices and bills are restated at the day&apos;s rate; the difference is an unrealised exchange gain or loss.</p>
              <form className="flex flex-wrap items-end gap-2.5">
                <div><label className="field-label" htmlFor="as_of">At the end of</label><DateInput plain className="field" id="as_of" name="as_of" defaultValue={asOf ?? lastMonthEnd} /></div>
                {currencies.map((c) => (
                  <div key={c}><label className="field-label" htmlFor={`rate_${c}`}>{c} rate</label>
                    <input className="field num" id={`rate_${c}`} name={`rate_${c}`} inputMode="decimal" defaultValue={given[c] ?? preview?.preview?.rates[c] ?? ""} /></div>
                ))}
                <button type="submit" className="btn">Preview</button>
              </form>
              {preview?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{preview.error}</p>}
              {preview?.preview && (
                preview.preview.lines.length === 0 ? <p className="mt-3 text-[14px] text-muted">Nothing to revalue at <Dt v={preview.preview.as_of} />.</p> : (
                  <>
                    <div className="mt-4 overflow-x-auto">
                      <table className="tbl">
                        <thead><tr><th scope="col">What</th><th scope="col">Kind</th><th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">In the books</th><th scope="col" className="!text-right">At the rate</th><th scope="col" className="!text-right">Difference</th></tr></thead>
                        <tbody>
                          {preview.preview.lines.map((l, i) => (
                            <tr key={i}>
                              <td>{l.label}</td><td className="text-[13px] text-muted">{KIND[l.kind] ?? l.kind}</td>
                              <td className="num text-right"><span className="mr-1 text-muted">{l.currency}</span>{formatAmount(l.amount)}</td>
                              <td className="num text-right">{formatAmount(l.booked)}</td>
                              <td className="num text-right">{formatAmount(l.restated)} <span className="text-[12px] text-muted">@ {l.rate}</span></td>
                              <td className="num text-right"><Amount value={l.difference} /></td>
                            </tr>
                          ))}
                        </tbody>
                        <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={5}>{preview.preview.net.startsWith("-") ? "Unrealised loss" : "Unrealised gain"}</td><td className="num text-right">{formatAmount(preview.preview.net.replace("-", ""))}</td></tr></tfoot>
                      </table>
                    </div>
                    <div className="mt-4"><PostRevaluation asOf={preview.preview.as_of} rates={preview.preview.rates} /></div>
                  </>
                )
              )}
            </Card>
          )}
          <Card className="!p-2" label="Runs">
            <h2 className="px-3 pt-2 text-[15px] font-bold">Revaluations</h2>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">At</th><th scope="col">Rates</th><th scope="col">Entry</th><th scope="col">Reversed</th><th scope="col">By</th><th scope="col" className="!text-right">Net</th></tr></thead>
                <tbody>
                  {runs.data.length === 0 && <tr><td colSpan={6} className="text-muted">No revaluation yet.</td></tr>}
                  {runs.data.map((r) => (
                    <tr key={r.id}>
                      <td className="num"><Dt v={r.as_of} /></td>
                      <td className="num text-[13px]">{Object.entries(r.rates).map(([c, v]) => `${c} ${v}`).join(", ")}</td>
                      <td className="num"><Link href={`/journal/${encodeURIComponent(r.entry.id)}`} className="hover:underline">#{r.entry.number}</Link></td>
                      <td className="num"><Link href={`/journal/${encodeURIComponent(r.reversal.id)}`} className="hover:underline">#{r.reversal.number}</Link> on <Dt v={r.reversal.date} /></td>
                      <td>{r.by ?? "—"}</td>
                      <td className="num text-right">{r.net.startsWith("-") ? `loss ${formatAmount(r.net.slice(1))}` : `gain ${formatAmount(r.net)}`}</td>
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

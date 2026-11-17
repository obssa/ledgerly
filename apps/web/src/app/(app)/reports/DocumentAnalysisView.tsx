import { Card, PageTitle } from "@/components/ui";
import { getDocumentAnalysis, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { ExportLink, ReportsNav } from "./ReportsNav";

/** Sales or purchases in a period by customer or supplier, item or month (credits taken off, base currency). */
export async function DocumentAnalysisView({ side, q }: { side: "sales" | "purchases"; q: Record<string, string | string[] | undefined> }) {
  const party = side === "sales" ? "Customer" : "Supplier";
  const groups: [string, string][] = [["contact", party], ["item", "Item"], ["month", "Month"], ...(side === "sales" ? [["salesperson", "Salesperson"] as [string, string]] : [])];
  const group = groups.some(([g]) => g === q.group) ? (q.group as string) : "contact";
  const period = await getReportPeriod(q);
  const r = await getDocumentAnalysis(side, { group, ...period });
  const label = groups.find(([g]) => g === group)?.[1] ?? party;
  const href = (key: string | null) => (group === "contact" && key ? `/${side === "sales" ? "customers" : "suppliers"}/${encodeURIComponent(key)}` : null);

  return (
    <>
      <PageTitle eyebrow="Reports / Customers and suppliers" title={side === "sales" ? "Sales analysis" : "Purchases analysis"} actions={<ExportLink report={side} params={{ group, ...period }} />} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="group">By</label>
          <select className="field" id="group" name="group" defaultValue={group}>{groups.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></div>
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={period.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={period.to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current={`/reports/${side}`} />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label={side === "sales" ? "Sales" : "Purchases"}>
            <div className="px-3 pt-2 text-[13px] text-muted">{periodLabel(r.from, r.to)} · {r.currency} · before tax · {side === "sales" ? "credit notes" : "supplier credits"} taken off</div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">{label}</th>{group === "item" && <th scope="col" className="!text-right">Quantity</th>}<th scope="col" className="!text-right">Documents</th>
                  <th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Share</th></tr></thead>
                <tbody>
                  {r.rows.length === 0 && <tr><td colSpan={6} className="text-muted">Nothing posted in the period.</td></tr>}
                  {r.rows.map((row) => {
                    const link = href(row.key);
                    return (
                      <tr key={row.key ?? "none"}>
                        <td>{link ? <a href={link} className="hover:underline">{row.label}</a> : <span className={row.key ? "" : "text-muted"}>{row.label}</span>}</td>
                        {group === "item" && <td className="num text-right">{row.quantity ?? ""}</td>}
                        <td className="num text-right">{row.documents}</td>
                        <td className="num text-right font-semibold">{formatAmount(row.amount)}</td><td className="num text-right">{formatAmount(row.tax)}</td>
                        <td className="num text-right">{row.share === null ? "—" : `${row.share}%`}</td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={group === "item" ? 3 : 2}>Total</td>
                  <td className="num text-right">{formatAmount(r.totals.amount)}</td><td className="num text-right">{formatAmount(r.totals.tax)}</td><td /></tr></tfoot>
              </table>
            </div>
          </Card>
        )}
      </div>
    </>
  );
}

import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getApAgeing } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "AP ageing" };

const COLUMNS = [
  ["current", "Not yet due"], ["days_1_30", "1–30 days"], ["days_31_60", "31–60"], ["days_61_90", "61–90"], ["over_90", "Over 90"],
  ["credits", "Advances"], ["total", "We owe"],
] as const;

/** What the company owes suppliers by how late it is. Each supplier's total is their balance on accounts payable. */
export default async function ApAgeingPage({ searchParams }: PageProps<"/reports/ap-ageing">) {
  const { as_of } = await searchParams;
  const asOf = typeof as_of === "string" && /^\d{4}-\d{2}-\d{2}$/.test(as_of) ? as_of : undefined;
  const ageing = await getApAgeing(asOf);

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Payables</>} title="AP ageing" />
      {ageing === null ? (
        <Card><p className="text-sm text-muted">Not available in the demo.</p></Card>
      ) : (
        <>
          <form className="mb-4 flex flex-wrap items-end gap-2.5">
            <div>
              <label className="field-label" htmlFor="as_of">Days late counted at</label>
              <input className="field" id="as_of" name="as_of" type="date" defaultValue={ageing.as_of} />
            </div>
            <button type="submit" className="btn">Show</button>
          </form>
          <Card className="!p-2" label="AP ageing">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr>
                    <th scope="col">Supplier</th>
                    {COLUMNS.map(([k, label]) => <th key={k} scope="col" className="!text-right">{label}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {ageing.rows.length === 0 && <tr><td colSpan={COLUMNS.length + 1} className="text-muted">Nothing is owed to suppliers.</td></tr>}
                  {ageing.rows.map((r) => (
                    <tr key={r.contact_id}>
                      <td className="font-semibold"><Link href={`/suppliers/${encodeURIComponent(r.contact_id)}/statement`} className="hover:underline">{r.name}</Link></td>
                      {COLUMNS.map(([k]) => (
                        <td key={k} className={`num text-right ${k === "total" ? "font-semibold" : ""} ${k === "over_90" && r.over_90 !== "0.00" ? "text-bad" : ""}`}>
                          {r[k] === "0.00" ? "—" : formatAmount(r[k])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
                {ageing.rows.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-ink font-bold">
                      <td>Total</td>
                      {COLUMNS.map(([k]) => <td key={k} className="num text-right">{formatAmount(ageing.totals[k])}</td>)}
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </Card>
          <p className="mt-3 text-[13px] text-muted">Amounts in the company&apos;s currency, each bill at its own rate. The total equals accounts payable in the trial balance.</p>
        </>
      )}
    </>
  );
}

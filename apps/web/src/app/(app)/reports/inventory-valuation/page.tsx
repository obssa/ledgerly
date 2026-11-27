import { CompareFilter } from "@/components/CompareFilter";
import { CompareTable } from "@/components/CompareTable";
import { measureLines } from "@/lib/compareLinesMeasure";
import { COMPARE_FIELDS, compareQuery, pickAsOfComparisons, pickDiff } from "@/lib/compare";
import { formatDate } from "@/lib/dates";
import { asOfLabel } from "@/lib/periods";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { getInventoryValuation, getReportAsOf } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { perUnit } from "@/lib/decimal";
import { ExportLink } from "../ReportsNav";

export const metadata = { title: "Inventory valuation" };

/** Stock at a date, per item and warehouse, next to the inventory account in the ledger. */
export default async function InventoryValuationPage({ searchParams }: PageProps<"/reports/inventory-valuation">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const cmp = pickAsOfComparisons(q, asOf, at.ctx);
  const diff = pickDiff(q, "pct");
  const prefs = { calendar: at.ctx.calendar, dateFormat: at.ctx.dateFormat ?? "DD/MM/YYYY" } as const;
  const headers = [formatDate(asOf, prefs), ...cmp.dates.map((d) => d.label)];
  const comparedWith = cmp.dates.length ? `Compared with ${cmp.dates.map((d) => d.label).join(", ")}` : null;
  const [v, ...others] = await Promise.all([asOf, ...cmp.dates.map((d) => d.asOf)].map((d) => getInventoryValuation(d)));
  const lines = cmp.dates.length && v && others.every(Boolean)
    ? measureLines([v, ...others] as NonNullable<typeof v>[], (x) => x.rows, (row) => `${row.item_id}|${row.warehouse}`, (row) => row.value,
      (row) => `${row.name}${row.sku ? ` (${row.sku})` : ""} · ${row.warehouse}`, { label: "Total", value: (x) => x.stock_value })
    : null;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Inventory</>} title="Inventory valuation" actions={<ExportLink report="inventory-valuation" params={{ as_of: asOf, ...compareQuery(cmp.slots, diff) }} />} />
      {v === null ? <Card><p className="text-sm text-muted">Not available in the demo.</p></Card> : (
        <>
          <AsOfFilter picked={at.picked} keep={q} fields={COMPARE_FIELDS}>
            <CompareFilter slots={cmp.slots} diff={diff} asOf />
          </AsOfFilter>
          {v.difference !== "0.00" && (
            <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
              The inventory account differs from the stock by {formatAmount(v.difference)}: a journal entry was posted to it directly. Correct it with a stock adjustment.
            </p>
          )}
          <Card className="!p-2" label="Inventory valuation">
            <div className="px-3 pt-3"><ReportHeader title="Inventory valuation" period={asOfLabel(asOf, at.ctx)} filters={["Values at cost", comparedWith]} /></div>
            {lines ? <CompareTable first="Item · warehouse" lines={lines} headers={headers} diff={diff} /> : (<div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Item</th><th scope="col">SKU</th><th scope="col">Warehouse</th><th scope="col" className="!text-right">Quantity</th><th scope="col" className="!text-right">Average cost</th><th scope="col" className="!text-right">Value</th></tr></thead>
                <tbody>
                  {v.rows.length === 0 && <tr><td colSpan={5} className="text-muted">No stock at this date.</td></tr>}
                  {v.rows.map((r) => (
                    <tr key={`${r.item_id}-${r.warehouse}`}>
                      <td className="font-semibold"><Link href={`/products/${encodeURIComponent(r.item_id)}`} className="hover:underline">{r.name}</Link></td>
                      <td className="num">{r.sku ?? "—"}</td>
                      <td>{r.warehouse}</td>
                      <td className="num text-right">{formatAmount(r.quantity)} {r.unit}</td>
                      <td className="num text-right text-muted">{perUnit(r.value, r.quantity) === null ? "—" : formatAmount(perUnit(r.value, r.quantity)!)}</td>
                      <td className="num text-right">{formatAmount(r.value)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-ink font-bold"><td colSpan={4}>Stock</td><td className="num text-right">{formatAmount(v.stock_value)}</td></tr>
                  <tr><td colSpan={4}>Inventory account in the ledger</td><td className="num text-right">{formatAmount(v.inventory_account)}</td></tr>
                </tfoot>
              </table>
            </div>)}
            <ReportFooter basis={null} />
          </Card>
        </>
      )}
    </>
  );
}

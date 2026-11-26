import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { exportPeriod } from "@/lib/periodParams";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { rangeLabel } from "@/lib/periods";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getReportPeriod, getStockMovement, getWarehouses } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Stock movement" };

const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

/** Module 4/10: per stock item, on hand at the start, what came in and went out, and on hand at the end of a period. */
export default async function StockMovementPage({ searchParams }: PageProps<"/reports/stock-movement">) {
  const q = await searchParams;
  const period = await getReportPeriod(q);
  const warehouseId = uuid(q.warehouse_id);
  const [r, warehouses] = await Promise.all([getStockMovement({ from: period.from, to: period.to, warehouse_id: warehouseId }), getWarehouses()]);

  return (
    <>
      <PageTitle eyebrow="Reports / Inventory" title="Stock movement" actions={<ExportLink report="stock-movement" params={{ ...exportPeriod(period.picked), warehouse_id: warehouseId }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["warehouse_id"]}>
        <div className="min-w-[180px]"><label className="field-label" htmlFor="warehouse_id">Warehouse</label>
          <select className="field" id="warehouse_id" name="warehouse_id" defaultValue={warehouseId ?? ""}><option value="">All warehouses</option>
            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></div>
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/stock-movement" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Stock movement">
            <div className="px-3 pt-3"><ReportHeader title="Stock movement" period={rangeLabel(period.picked, period.ctx.calendar)}
              filters={[r.warehouse?.name ?? "All warehouses (transfers between them left out)", "Values at cost"]} /></div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Item</th><th scope="col" className="!text-right">Opening</th><th scope="col" className="!text-right">In</th>
                    <th scope="col" className="!text-right">Out</th><th scope="col" className="!text-right">Of it sold</th><th scope="col" className="!text-right">Closing</th>
                    <th scope="col" className="!text-right">Closing value</th></tr>
                </thead>
                <tbody>
                  {r.rows.length === 0 && <tr><td colSpan={7} className="text-muted">No stock moved or held in the period.</td></tr>}
                  {r.rows.map((x) => (
                    <tr key={x.item.id}>
                      <td><Link href={`/products/${encodeURIComponent(x.item.id)}`} className="font-semibold hover:underline">{x.item.name}</Link>
                        {x.item.sku && <span className="block text-[11px] text-muted">{x.item.sku}</span>}</td>
                      <td className="num text-right">{formatAmount(x.opening_qty)}<span className="block text-[11px] text-muted">{formatAmount(x.opening_value)}</span></td>
                      <td className="num text-right">{formatAmount(x.in_qty)}<span className="block text-[11px] text-muted">{formatAmount(x.in_value)}</span></td>
                      <td className="num text-right">{formatAmount(x.out_qty)}<span className="block text-[11px] text-muted">{formatAmount(x.out_value)}</span></td>
                      <td className="num text-right">{formatAmount(x.sold_qty)}</td>
                      <td className="num text-right">{formatAmount(x.closing_qty)} {x.item.unit}</td>
                      <td className="num text-right font-semibold">{formatAmount(x.closing_value)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-ink font-bold">
                    <td>Total value</td><td className="num text-right">{formatAmount(r.totals.opening_value)}</td><td className="num text-right">{formatAmount(r.totals.in_value)}</td>
                    <td className="num text-right">{formatAmount(r.totals.out_value)}</td><td /><td /><td className="num text-right">{formatAmount(r.totals.closing_value)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <ReportFooter basis={null} currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}

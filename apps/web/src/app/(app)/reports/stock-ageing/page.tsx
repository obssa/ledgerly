import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { AsOfFilter } from "@/components/DateRangeFilter";
import { getReportAsOf, getStockAgeing, getWarehouses } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { STOCK_BANDS } from "@/lib/reports";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Stock ageing" };

const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

/** Module 4/10: what is on hand at a date, by days since it last sold: slow-moving (91 to 180 days) and dead stock (over 180, or never sold). */
export default async function StockAgeingPage({ searchParams }: PageProps<"/reports/stock-ageing">) {
  const q = await searchParams;
  const at = await getReportAsOf(q);
  const asOf = at.asOf;
  const warehouseId = uuid(q.warehouse_id);
  const [r, warehouses] = await Promise.all([getStockAgeing({ as_of: asOf, warehouse_id: warehouseId }), getWarehouses()]);

  return (
    <>
      <PageTitle eyebrow="Reports / Inventory" title="Stock ageing" actions={<ExportLink report="stock-ageing" params={{ as_of: asOf, warehouse_id: warehouseId }} />} />
      <AsOfFilter picked={at.picked} keep={q} fields={["warehouse_id"]}>
        <div className="min-w-[180px]"><label className="field-label" htmlFor="warehouse_id">Warehouse</label>
          <select className="field" id="warehouse_id" name="warehouse_id" defaultValue={warehouseId ?? ""}><option value="">All warehouses</option>
            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select></div>
      </AsOfFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/stock-ageing" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, or for that date.</p> : (
          <div className="min-w-0 flex-[4_1_560px]">
            <div className="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))]">
              {r.bands.map((b) => (
                <Card key={b.band} label={STOCK_BANDS[b.band]?.label ?? b.band}>
                  <div className="text-[12px] font-semibold text-muted">{STOCK_BANDS[b.band]?.label ?? b.band}</div>
                  <div className="num mt-1 text-[18px] font-bold">{formatAmount(b.value)}</div>
                  <div className="text-[12px] text-muted">{b.items} {b.items === 1 ? "item" : "items"}{STOCK_BANDS[b.band]?.note ? ` · ${STOCK_BANDS[b.band].note}` : ""}</div>
                </Card>
              ))}
            </div>
            <Card className="!p-2" label="Stock on hand by days since it last sold">
              <div className="px-3 pt-2 text-[13px] text-muted">On hand at {r.as_of} · {r.warehouse?.name ?? "All warehouses"} · values in {r.currency} at cost</div>
              <div className="overflow-x-auto">
                <table className="tbl">
                  <thead><tr><th scope="col">Item</th><th scope="col" className="!text-right">On hand</th><th scope="col" className="!text-right">Value</th>
                    <th scope="col">Last sold</th><th scope="col">Last received</th><th scope="col" className="!text-right">Days since sale</th><th scope="col">Band</th></tr></thead>
                  <tbody>
                    {r.rows.length === 0 && <tr><td colSpan={7} className="text-muted">Nothing on hand at this date.</td></tr>}
                    {r.rows.map((x) => {
                      const band = STOCK_BANDS[x.band];
                      return (
                        <tr key={x.item.id}>
                          <td><Link href={`/products/${encodeURIComponent(x.item.id)}`} className="font-semibold hover:underline">{x.item.name}</Link>
                            {x.item.sku && <span className="block text-[11px] text-muted">{x.item.sku}</span>}</td>
                          <td className="num text-right">{formatAmount(x.quantity)} {x.item.unit}</td>
                          <td className="num text-right font-semibold">{formatAmount(x.value)}</td>
                          <td className="num">{x.last_sold_on ?? "—"}</td>
                          <td className="num">{x.last_received_on ?? "—"}</td>
                          <td className="num text-right">{x.days_since_sale ?? "—"}</td>
                          <td>{band?.note ? <Pill tone={band.note === "dead stock" ? "bad" : "warn"}>{band.note}</Pill> : <span className="text-[13px] text-muted">{band?.label ?? x.band}</span>}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                  <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={2}>Total</td><td className="num text-right">{formatAmount(r.total_value)}</td><td colSpan={4} /></tr></tfoot>
                </table>
              </div>
            </Card>
          </div>
        )}
      </div>
    </>
  );
}

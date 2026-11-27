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
  const v = await getInventoryValuation(asOf);

  return (
    <>
      <PageTitle eyebrow={<><Link href="/reports" className="hover:underline">Reports</Link> / Inventory</>} title="Inventory valuation" actions={<ExportLink report="inventory-valuation" params={{ as_of: asOf }} />} />
      {v === null ? <Card><p className="text-sm text-muted">Not available in the demo.</p></Card> : (
        <>
          <AsOfFilter picked={at.picked} keep={q} />
          {v.difference !== "0.00" && (
            <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
              The inventory account differs from the stock by {formatAmount(v.difference)}: a journal entry was posted to it directly. Correct it with a stock adjustment.
            </p>
          )}
          <Card className="!p-2" label="Inventory valuation">
            <div className="px-3 pt-3"><ReportHeader title="Inventory valuation" period={asOfLabel(asOf, at.ctx)} filters={["Values at cost"]} /></div>
            <div className="overflow-x-auto">
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
            </div>
            <ReportFooter basis={null} />
          </Card>
        </>
      )}
    </>
  );
}

import { isDateKey, isMomentKey } from "@/lib/columns";
import { Dt } from "@/components/Dt";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ColumnCustomizer } from "@/components/ColumnCustomizer";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { connection } from "next/server";
import { Card, PageTitle } from "@/components/ui";
import { chooseColumns, rightAligned } from "@/lib/columns";
import { getBinCard, getItem, getWarehouses, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { BIN_COLUMNS, BIN_DEFAULTS, binRow, type BinColumn, type BinRow } from "@/lib/ledgerColumns";
import { periodQuery } from "@/lib/periodParams";
import { rangeLabel } from "@/lib/periods";

export const metadata = { title: "Bin card" };

const KIND: Record<string, string> = { opening: "Opening", purchase: "Received", sale: "Sold", sale_return: "Returned in", purchase_return: "Returned out",
  transfer_in: "Transfer in", transfer_out: "Transfer out", adjustment: "Adjustment", production: "Production", project_issue: "Issued to a project",
  landed_cost: "Landed cost" };
const DOC_PATH: Record<string, string> = { Invoice: "/invoices", "Credit note": "/credit-notes", Bill: "/bills", "Cash sale": "/sales-receipts" };
const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/i.test(v) ? v : undefined);
const def = (c: BinColumn) => BIN_COLUMNS.find((d) => d.key === c)!;

function cell(c: BinColumn, r: BinRow) {
  const v = r[c];
  if (v === null || v === "") return "";
  if (isDateKey(c) || isMomentKey(c)) return <Dt v={String(v)} time={isMomentKey(c)} />;
  if (c === "kind") return KIND[v] ?? v;
  if ((c === "qty_in" || c === "qty_out") && v === "0.00") return "";
  if (def(c).kind === "money" || def(c).kind === "count") return formatAmount(v);
  if (c === "document" && r.document_id) {
    const path = DOC_PATH[v.replace(/ \S+$/, "")];
    return path ? <Link href={`${path}/${encodeURIComponent(r.document_id)}`} className="hover:underline">{v}</Link> : v;
  }
  return v;
}

/**
 * The stock card of one item in one warehouse: every move in and out, with the running quantity and value. Customize
 * adds each move's document, its customer or supplier, the value moved and who made it.
 */
export default async function BinCardPage({ searchParams }: PageProps<"/inventory/bin-card">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "this_fiscal_year_to_date");
  const itemId = uuid(q.item);
  const warehouseId = uuid(q.warehouse);
  if (!itemId || !warehouseId) notFound();
  await connection();
  const { from, to } = period;
  const [item, warehouses, card] = await Promise.all([getItem(itemId), getWarehouses(), getBinCard({ item_id: itemId, warehouse_id: warehouseId, from, to })]);
  if (!item || !card) notFound();
  const warehouse = warehouses.find((w) => w.id === warehouseId);
  const cols = chooseColumns(q, BIN_COLUMNS, BIN_DEFAULTS);
  const custom = Boolean(q.cols) || q.customize === "1";
  const keep = { ...periodQuery(period.picked), item: itemId, warehouse: warehouseId };
  const href = (extra: Record<string, string | undefined>) =>
    `/inventory/bin-card?${new URLSearchParams(Object.entries({ ...keep, ...extra }).filter(([, v]) => v) as [string, string][])}`;
  const align = (c: BinColumn) => (rightAligned(def(c).kind) ? "num text-right" : c === "date" ? "num" : "");
  const rows = card.moves.map(binRow);

  return (
    <>
      <PageTitle eyebrow={<><Link href={`/products/${encodeURIComponent(item.id)}`} className="hover:underline">{item.name}</Link> / Bin card</>}
        title={<>Bin card · {warehouse?.name ?? "Warehouse"}</>} />
      <DateRangeFilter picked={period.picked} keep={{ cols: custom ? cols.join(",") : undefined }} fields={["item", "warehouse"]}>
        <input type="hidden" name="item" value={itemId} /><input type="hidden" name="warehouse" value={warehouseId} />
      </DateRangeFilter>
      <ColumnCustomizer defs={BIN_COLUMNS} cols={cols} keep={keep} moveHref={(next) => href({ cols: next.join(",") })} resetHref={href({})} />
      <Card className="!p-2" label="Bin card">
        <div className="px-3 pt-3">
          <ReportHeader title="Bin card" period={rangeLabel(period.picked, period.ctx)} filters={[`Item: ${item.name}`, warehouse ? `Warehouse: ${warehouse.name}` : null]} />
        </div>
        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Bin card">
          <table className="tbl">
            <thead><tr>{cols.map((c) => <th key={c} scope="col" className={align(c).includes("text-right") ? "!text-right" : ""}>{def(c).label}</th>)}</tr></thead>
            <tbody>
              <tr className="font-semibold">{cols.map((c, i) => (
                <td key={c} className={align(c)}>{c === "balance_qty" ? formatAmount(card.opening.quantity) : c === "balance_value" ? formatAmount(card.opening.value) : i === 0 ? `Brought forward at ${from}` : ""}</td>
              ))}</tr>
              {rows.length === 0 && <tr><td colSpan={cols.length} className="py-6 text-center text-muted">No moves in this period.</td></tr>}
              {rows.map((r) => <tr key={r.id}>{cols.map((c) => <td key={c} className={align(c)}>{cell(c, r)}</td>)}</tr>)}
            </tbody>
          </table>
        </div>
        <ReportFooter basis={null} />
      </Card>
    </>
  );
}

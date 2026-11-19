import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getStockBatches } from "@/lib/data";

export const metadata = { title: "Batches and expiry" };

const WINDOWS = [30, 90, 365] as const;

/** Module 4: what is on hand by batch, the first to expire first (074); a window keeps what expires by then. */
export default async function BatchesPage({ searchParams }: PageProps<"/inventory/batches">) {
  const { within } = await searchParams;
  const days = typeof within === "string" && /^\d{1,4}$/.test(within) ? Number(within) : undefined;
  const result = await getStockBatches(days);
  const rows = result?.data ?? [];

  return (
    <>
      <PageTitle eyebrow={<><Link href="/inventory" className="hover:underline">Stock on hand</Link> / Batches</>} title="Batches and expiry" />
      <nav aria-label="Expiring within" className="mb-3 flex flex-wrap gap-2 text-[13px]">
        <Link href="/inventory/batches" className={`btn !py-1 ${days === undefined ? "btn-primary" : ""}`}>All on hand</Link>
        {WINDOWS.map((d) => (
          <Link key={d} href={`/inventory/batches?within=${d}`} className={`btn !py-1 ${days === d ? "btn-primary" : ""}`}>Expiring within {d} days</Link>
        ))}
      </nav>
      <Card className="!p-2" label="Batches">
        {rows.length === 0 ? (
          <p className="p-3 text-sm text-muted">{days === undefined ? "No stock held by batch. Set an item to be tracked by batch before its first stock move." : `Nothing on hand expires within ${days} days.`}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Item</th><th scope="col">Batch</th><th scope="col">Expires</th><th scope="col">Warehouse</th><th scope="col" className="!text-right">On hand</th></tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={`${r.batch_id}-${r.warehouse_id}`}>
                    <td><Link href={`/products/${encodeURIComponent(r.item_id)}`} className="hover:underline">{r.item_name}</Link></td>
                    <td className="num font-semibold">{r.number}</td>
                    <td className="num">{r.expires_on ?? "—"} {r.expired && <Pill tone="bad">Expired</Pill>}</td>
                    <td>{r.warehouse}</td>
                    <td className="num text-right">{r.quantity} {r.unit}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
